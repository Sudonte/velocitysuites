<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\BookingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReceptionistController extends Controller
{
    protected NotificationService $notificationService;
    protected BookingService $bookingService;

    public function __construct(NotificationService $notificationService, BookingService $bookingService)
    {
        $this->notificationService = $notificationService;
        $this->bookingService = $bookingService;
    }

    /**
     * Display the receptionist dashboard.
     */
    public function dashboard(): View
    {
        // Status-driven counts that mirror the actual work queues, so any
        // action (confirm, check-in, check-out) moves these immediately -
        // check-in/check-out are no longer date-gated.
        $availableRooms = Room::where('status', 'available')->count();
        $bookingRequests = Reservation::where('status', 'pending')->count();
        $awaitingCheckIn = Reservation::where('status', 'confirmed')->count();
        $inHouseGuests = Reservation::where('status', 'checked_in')->count();

        // Today's schedule stays date-based - it's a schedule. Guests due to
        // check in today (not yet checked in) and guests due to check out
        // today (still in house).
        $todayCheckIns = Reservation::with(['guest.user', 'room'])
            ->whereDate('check_in', today())
            ->where('status', 'confirmed')
            ->get();

        $todayCheckOuts = Reservation::with(['guest.user', 'room'])
            ->whereDate('check_out', today())
            ->where('status', 'checked_in')
            ->get();

        return view('receptionist.dashboard', compact(
            'availableRooms',
            'bookingRequests',
            'awaitingCheckIn',
            'inHouseGuests',
            'todayCheckIns',
            'todayCheckOuts'
        ));
    }

    /**
     * The central Reservations list: every reservation request regardless
     * of payment state, filterable by status (including the derived
     * "awaiting payment"). Processing (room assignment, confirm, reject)
     * happens on the show page. bookingsIndex() remains the paid subset
     * for stay/payment management - the Booking module proper.
     */
    public function reservationsIndex(Request $request): View
    {
        $query = Reservation::with(['guest.user', 'room', 'roomType', 'booking.billing.payments']);

        $this->applyReservationFilters($query, $request);

        $reservations = $query->latest('check_in')->paginate(15);

        return view('receptionist.reservations.index', compact('reservations'));
    }

    /**
     * List reservations that HAVE been paid/booked (read-only), with
     * payment/billing status - the "Bookings" side of the split.
     */
    public function bookingsIndex(Request $request): View
    {
        $query = Reservation::with(['guest.user', 'room', 'booking.billing.payments'])
            ->whereHas('booking');

        $this->applyReservationFilters($query, $request);

        $reservations = $query->latest('check_in')->paginate(15);

        return view('receptionist.bookings.index', compact('reservations'));
    }

    /**
     * Shared status/search filters for reservationsIndex/bookingsIndex.
     * "awaiting_payment" is a derived filter (not a real status column) -
     * still-open reservations (pending/confirmed) that haven't become a
     * Booking yet, i.e. still need a payment or a staff-side conversion.
     */
    private function applyReservationFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            if ($request->status === 'awaiting_payment') {
                $query->whereIn('status', ['pending', 'confirmed'])->whereDoesntHave('booking');
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('guest.user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhereHas('room', function ($q) use ($search) {
                $q->where('room_number', 'like', "%{$search}%");
            });
        }
    }

    /**
     * Show a reservation. Pending reservations get their assignable-rooms
     * list loaded too, since the show page is now also where the
     * receptionist assigns a room, confirms, or rejects a request
     * (the standalone "Booking Requests" queue was merged in here).
     */
    public function reservationShow(Reservation $reservation): View
    {
        $reservation->load(['guest.user', 'room', 'roomType', 'booking.billing.payments', 'amenityRequests.amenity']);

        $assignableRooms = $reservation->status === 'pending'
            ? $this->assignableRoomsFor($reservation)
            : collect();

        return view('receptionist.reservations.show', compact('reservation', 'assignableRooms'));
    }

    /**
     * Show the payment-collection form to convert a plain Reservation
     * into a Booking (guest decided to pay - in person, over the phone,
     * etc.). Requires the reservation to already be confirmed (room
     * assigned) - collecting payment before a request is even confirmed
     * would be collecting money for a room that might not be granted.
     */
    public function convertToBookingForm(Reservation $reservation): View
    {
        if ($reservation->booking) {
            abort(422, 'This reservation is already a booking.');
        }

        if ($reservation->status === 'pending') {
            abort(422, 'This reservation must be confirmed (room assigned) before it can be converted to a booking.');
        }

        $reservation->load(['guest.user', 'roomType']);
        $quote = $this->bookingService->quoteRoomCharge($reservation);

        return view('receptionist.reservations.convert', compact('reservation', 'quote'));
    }

    /**
     * Record the payment and create the Booking. Staff directly
     * collected this payment, so it's trusted immediately (unlike a
     * guest's own self-service submission) - see BookingService.
     */
    public function convertToBooking(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->booking) {
            return back()->with('error', 'This reservation is already a booking.');
        }

        if ($reservation->status === 'pending') {
            return back()->with('error', 'Confirm this reservation (assign a room) before collecting payment.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required_if:payment_method,gcash|nullable|string|max:255',
            'amount_paid' => 'required|numeric|min:0.01',
        ]);

        $this->bookingService->recordPayment($reservation, $validated, staffRecorded: true);

        $this->notificationService->notifyPaymentReceived(
            $reservation->guest->user,
            (float) $validated['amount_paid'],
            $reservation->roomType->name
        );

        return redirect()->route('receptionist.reservations.show', $reservation)
            ->with('success', 'Payment recorded - reservation converted to a booking.');
    }

    /**
     * Queue of guest-submitted payments awaiting staff verification (the
     * self-service counterpart to the pending-reservation room-assignment
     * step on reservationShow - see BookingService::recordPayment).
     */
    public function pendingPaymentsIndex(): View
    {
        $payments = Payment::with(['billing.booking.reservation.guest.user', 'billing.booking.reservation.roomType'])
            ->where('payment_status', 'pending')
            ->orderBy('created_at')
            ->paginate(15);

        return view('receptionist.payments.pending', compact('payments'));
    }

    /**
     * Approve a guest-submitted payment: flips it to completed and
     * confirms the booking.
     */
    public function verifyPayment(Payment $payment): RedirectResponse
    {
        if ($payment->payment_status !== 'pending') {
            return back()->with('error', 'This payment has already been processed.');
        }

        $this->bookingService->verifyPayment($payment);

        $reservation = $payment->billing->booking?->reservation;
        if ($reservation) {
            $this->notificationService->notifyPaymentReceived(
                $reservation->guest->user,
                (float) $payment->amount_paid,
                $reservation->roomType->name ?? 'your room'
            );
        }

        return back()->with('success', 'Payment verified and booking confirmed.');
    }

    /**
     * Rooms of the reservation's requested type that can be assigned to it:
     * in service, currently available, big enough for the party (capacity
     * varies per room within a type), and with no confirmed/checked-in
     * reservation overlapping the requested dates.
     */
    private function assignableRoomsFor(Reservation $reservation)
    {
        return Room::where('room_type_id', $reservation->room_type_id)
            ->where('room_capacity', '>=', $reservation->number_of_guests)
            ->where('status', 'available')
            ->whereDoesntHave('reservations', function ($q) use ($reservation) {
                $q->whereIn('status', ['confirmed', 'checked_in'])
                  ->where('id', '!=', $reservation->id)
                  ->where(function ($dates) use ($reservation) {
                      $dates->whereBetween('check_in', [$reservation->check_in, $reservation->check_out])
                            ->orWhereBetween('check_out', [$reservation->check_in, $reservation->check_out])
                            ->orWhere(function ($spanning) use ($reservation) {
                                $spanning->where('check_in', '<=', $reservation->check_in)
                                         ->where('check_out', '>=', $reservation->check_out);
                            });
                  });
            })
            ->orderBy('room_number')
            ->get();
    }

    /**
     * Create zero-charge, pre-approved amenity requests for every active
     * amenity-type promotion matching the reservation's room type. Skipped
     * if the reservation already has a zero-charge request for that
     * amenity (guards against double-granting on re-confirmation).
     */
    private function grantPromoAmenities(Reservation $reservation): void
    {
        $promos = Promotion::with('amenities')
            ->where('status', 'active')
            ->where('promo_type', 'amenity')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->where(function ($q) use ($reservation) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $reservation->room_type_id);
            })
            ->get();

        foreach ($promos as $promo) {
            foreach ($promo->amenities as $amenity) {
                $alreadyGranted = AmenityRequest::where('reservation_id', $reservation->id)
                    ->where('amenity_id', $amenity->id)
                    ->where('charge', 0)
                    ->exists();

                if (!$alreadyGranted) {
                    AmenityRequest::create([
                        'guest_id' => $reservation->guest_id,
                        'reservation_id' => $reservation->id,
                        'amenity_id' => $amenity->id,
                        'quantity' => $amenity->pivot->quantity,
                        'charge' => 0, // included free by the promotion
                        'status' => 'approved',
                    ]);
                }
            }
        }
    }

    /**
     * Assign a room to a pending booking request and confirm it.
     * The guest never picks a room number - the receptionist chooses the
     * physical room here, and confirmation only happens with a room set.
     */
    public function confirmReservation(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'pending') {
            return back()->with('error', 'Only pending reservations can be confirmed.');
        }

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
        ]);

        // The chosen room must be one of the actually-assignable rooms
        // (right type, available, no date conflict) - not just any room.
        $room = $this->assignableRoomsFor($reservation)->firstWhere('id', (int) $validated['room_id']);

        if (!$room) {
            return back()->with('error', 'That room cannot be assigned: it is not an available ' . $reservation->roomType->name . ' room for these dates.');
        }

        DB::transaction(function () use ($reservation, $room) {
            $reservation->update([
                'room_id' => $room->id,
                'status' => 'confirmed',
            ]);

            // Update booking status
            if ($reservation->booking) {
                $reservation->booking->update(['booking_status' => 'confirmed']);
            }

            // Mark the room as reserved to prevent double bookings
            $room->update(['status' => 'reserved']);

            // Grant active amenity-promo inclusions for this room type as
            // zero-charge approved amenity requests, so they appear on the
            // stay (and the final bill) at no cost.
            $this->grantPromoAmenities($reservation);

            // Notify the guest with their assigned room
            $this->notificationService->notifyReservationConfirmed(
                $reservation->guest->user,
                $room->room_name . ' (Room ' . $room->room_number . ')'
            );
        });

        // Stay on the same Reservation Details page instead of bouncing
        // back to the list - the receptionist just assigned the room and
        // likely wants to keep working this reservation (e.g. convert it
        // to a Booking next) without having to reopen it.
        return redirect()->route('receptionist.reservations.show', $reservation)
            ->with('success', 'Room ' . $room->room_number . ' assigned and reservation confirmed!');
    }

    /**
     * Reject a pending reservation.
     */
    public function rejectReservation(Request $request, Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'pending') {
            return back()->with('error', 'Only pending reservations can be rejected.');
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($reservation, $request) {
            $reservation->update(['status' => 'cancelled']);

            // Update booking status
            if ($reservation->booking) {
                $reservation->booking->update(['booking_status' => 'cancelled']);
            }

            // Release the room if one was already assigned (pending
            // requests usually have none yet).
            if ($reservation->room) {
                $reservation->room->update(['status' => 'available']);
            }

            // Notify the guest about rejection
            Notification::create([
                'user_id' => $reservation->guest->user_id,
                'title' => 'Reservation Rejected',
                'message' => 'Your booking request for a ' . $reservation->roomType->name . ' room has been rejected. Reason: ' . $request->reason,
                'category' => 'booking',
            ]);
        });

        // Same reasoning as confirmReservation() - stay on the reservation's
        // own page so the receptionist immediately sees the Cancelled state
        // instead of losing their place in the list.
        return redirect()->route('receptionist.reservations.show', $reservation)->with('success', 'Reservation rejected.');
    }

    /**
     * List reservations awaiting check-in: every confirmed reservation,
     * regardless of its scheduled date - early arrivals can be checked in
     * whenever their room is actually ready (room status is the real gate).
     */
    public function checkInIndex(): View
    {
        $reservations = Reservation::with(['guest.user', 'room'])
            ->where('status', 'confirmed')
            ->orderBy('check_in')
            ->paginate(15);

        return view('receptionist.check-in.index', compact('reservations'));
    }

    /**
     * Mark reservation as checked in. Date is not a gate - the room's
     * actual status is: a room still occupied by the previous guest or
     * under maintenance can't receive a new check-in.
     */
    public function checkIn(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'confirmed') {
            return back()->with('error', 'Only confirmed reservations can be checked in.');
        }

        if (in_array($reservation->room->status, ['occupied', 'maintenance'])) {
            return back()->with('error',
                'Room ' . $reservation->room->room_number . ' is not ready (' . $reservation->room->status . '). ' .
                'Free it up first or assign a different room.');
        }

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'checked_in']);

            // Update the booking's status to confirmed
            if ($reservation->booking) {
                $reservation->booking->update(['booking_status' => 'confirmed']);
            }

            // Mark the room as occupied
            $reservation->room->update(['status' => 'occupied']);

            // Notify guest and managers
            $this->notificationService->notifyCheckIn(
                $reservation->guest->user,
                $reservation->room->room_name
            );
        });

        return redirect()->route('receptionist.check-in.index')->with('success', 'Guest checked in successfully!');
    }

    /**
     * List reservations available for check-out: every checked-in guest,
     * regardless of scheduled departure date - a guest can leave early
     * (or late) whenever they settle their bill.
     */
    public function checkOutIndex(): View
    {
        $reservations = Reservation::with(['guest.user', 'room', 'booking.billing'])
            ->where('status', 'checked_in')
            ->orderBy('check_out')
            ->paginate(15);

        return view('receptionist.check-out.index', compact('reservations'));
    }

    /**
     * Open (or resume) the Billing Panel for a check-out in progress.
     * Creates a draft billing if one doesn't exist yet; does not change
     * reservation or room status.
     */
    public function checkOutBilling(Reservation $reservation)
    {
        if ($reservation->status !== 'checked_in') {
            return response()->json(['message' => 'Only checked-in reservations can be billed.'], 422);
        }

        // A guest who never pre-paid via "Book & Pay" has no Booking/
        // Billing yet at this point - generateBilling() now creates
        // them lazily (an implicit Reservation -> Booking conversion
        // happening right here at checkout) rather than requiring one
        // to already exist.
        $billing = $this->generateBilling($reservation);

        $reservation->load(['guest.user', 'room']);
        $billing->load('additionalCharges');

        $amenityRequests = AmenityRequest::with('amenity')
            ->where('reservation_id', $reservation->id)
            ->where('status', 'approved')
            ->get();

        return view('receptionist.check-out.partials.billing-panel', compact('reservation', 'billing', 'amenityRequests'));
    }

    /**
     * Discard a draft billing (no payments recorded yet) started from Check-Out.
     */
    public function checkOutCancelBilling(Billing $billing)
    {
        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot cancel a paid bill.'], 422);
        }

        if ($billing->payments()->where('payment_status', 'completed')->exists()) {
            return response()->json(['message' => 'This bill already has recorded payments and cannot be discarded.'], 422);
        }

        $billing->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Open the Payment Panel for a locked billing.
     */
    public function checkOutPaymentPanel(Billing $billing)
    {
        $billing->load(['booking.reservation.guest.user', 'booking.reservation.room', 'payments', 'additionalCharges']);

        $balance = $billing->balance;
        $amountPaidSoFar = (float) $billing->payments()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');

        return view('receptionist.check-out.partials.payment-panel', compact('billing', 'balance', 'amountPaidSoFar'));
    }

    /**
     * Browse room types (read-only card grid). The receptionist can see
     * inventory and status but cannot add or edit types/rooms.
     */
    public function roomsIndex(): View
    {
        $roomTypes = \App\Models\RoomType::withCount([
            'rooms',
            'rooms as available_rooms_count' => function ($q) {
                $q->where('status', 'available');
            },
        ])->orderBy('name')->get();

        return view('receptionist.rooms.index', compact('roomTypes'));
    }

    /**
     * Rooms of one type with their live statuses (read-only).
     */
    public function roomsShow(\App\Models\RoomType $roomType): View
    {
        $rooms = $roomType->rooms()->orderBy('room_number')->paginate(20);

        return view('receptionist.rooms.show', compact('roomType', 'rooms'));
    }

    /**
     * List all amenity requests.
     */
    public function amenitiesIndex(Request $request): View
    {
        $query = AmenityRequest::with(['guest.user', 'amenity']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $amenityRequests = $query->latest()->paginate(15);

        return view('receptionist.amenities.index', compact('amenityRequests'));
    }

    /**
     * Show form to create an amenity request for a reservation.
     */
    public function amenitiesCreate(Reservation $reservation): View
    {
        $amenities = Amenity::where('status', 'active')
            ->orderBy('amenity_name')
            ->get();

        return view('receptionist.amenities.create', compact('reservation', 'amenities'));
    }

    /**
     * Store a new amenity request, snapshotting the amenity's current charge.
     */
    public function amenitiesStore(Request $request, Reservation $reservation): RedirectResponse
    {
        $validated = $request->validate([
            'amenity_id' => 'required|exists:amenities,id',
            'quantity' => 'required|integer|min:1',
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $amenity = Amenity::findOrFail($validated['amenity_id']);

        AmenityRequest::create([
            'guest_id' => $reservation->guest_id,
            'reservation_id' => $reservation->id,
            'amenity_id' => $amenity->id,
            'quantity' => $validated['quantity'],
            // Snapshot the current charge per unit at the time of the request
            'charge' => (float) $amenity->charge,
            'status' => $validated['status'],
        ]);

        return redirect()->route('receptionist.amenities.index')->with('success', 'Amenity request added.');
    }

    /**
     * Update an amenity request status.
     */
    public function amenitiesUpdate(Request $request, AmenityRequest $amenityRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,approved,rejected',
        ]);

        $amenityRequest->update($validated);

        return back()->with('success', 'Amenity request updated.');
    }

    /**
     * Record a payment against a billing from the Payment Panel. Completes the
     * check-out (reservation + room status, notifications) only once the
     * balance reaches zero; a partial payment leaves the guest checked in.
     */
    public function recordPayment(Request $request, Billing $billing)
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required_if:payment_method,gcash|nullable|string|max:255',
            'amount_paid' => 'required|numeric|min:0.01',
        ]);

        $reservation = $billing->booking?->reservation;

        if (!$reservation || $reservation->status !== 'checked_in') {
            return response()->json(['message' => 'This reservation is not awaiting checkout.'], 422);
        }

        $completed = false;

        DB::transaction(function () use ($validated, $billing, $reservation, &$completed) {
            // Auto-generate reference for cash if blank
            if (empty($validated['reference_number'])) {
                $validated['reference_number'] = 'PAY-' . strtoupper(Str::random(10));
            }

            Payment::create([
                'billing_id' => $billing->id,
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'],
                'amount_paid' => $validated['amount_paid'],
                'payment_status' => 'completed',
                'payment_date' => now(),
            ]);

            $this->bookingService->recalculateBillingStatus($billing);
            $completed = $billing->fresh()->billing_status === 'paid';

            $guest = $reservation->guest->user;
            $roomName = $reservation->room->room_name;

            $this->notificationService->notifyPaymentReceived(
                $guest,
                (float) $validated['amount_paid'],
                $roomName
            );

            if ($completed) {
                $reservation->update(['status' => 'checked_out']);
                $reservation->room->update(['status' => 'available']);

                $this->notificationService->notifyCheckOut($guest, $roomName);
                $this->notificationService->notifyPaymentComplete($guest);
            } else {
                $this->notificationService->notifyManagerPayment(
                    $guest,
                    (float) $validated['amount_paid'],
                    $billing->billing_status,
                    $roomName
                );
            }
        });

        $billing->refresh();

        return response()->json([
            'completed' => $completed,
            'balance' => $billing->balance,
            'message' => $completed ? 'Payment complete. Guest checked out.' : 'Partial payment recorded.',
            'receipt_url' => $completed ? route('receptionist.billing.receipt', $billing) : null,
        ]);
    }

    /**
     * Get (or lazily create) the billing record for a reservation. Idempotent:
     * a guest who pre-paid via "Book & Pay" already has a Booking/Billing
     * with room_charge/discount locked in from BookingService::quoteRoomCharge
     * at that time (preserved here, not recomputed - a promo expiring
     * between booking and checkout shouldn't retroactively change what they
     * were quoted); a guest who never pre-paid gets both created fresh here.
     * Either way, extra-guest-fee and amenity charges (only knowable once
     * the stay is underway) are (re-)applied on top every time this runs.
     */
    private function generateBilling(Reservation $reservation): Billing
    {
        $booking = $this->bookingService->ensureBooking($reservation);
        $billing = $this->bookingService->ensureBilling($booking, $reservation);
        $this->bookingService->applyStayCharges($billing, $reservation);

        return $billing->fresh();
    }

    /**
     * Store a new additional charge for a billing (Billing Panel, AJAX).
     */
    public function storeAdditionalCharge(Request $request, Billing $billing)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|in:damage,lost_item,broken_equipment,mini_bar,laundry,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot add charges to a paid bill.'], 422);
        }

        DB::transaction(function () use ($billing, $validated) {
            AdditionalCharge::create([
                'billing_id' => $billing->id,
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'category' => $validated['category'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $billing->recalculateTotal();
        });

        return $this->chargesTableResponse($billing);
    }

    /**
     * Update an existing additional charge (Billing Panel, AJAX).
     */
    public function updateAdditionalCharge(Request $request, AdditionalCharge $additionalCharge)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|in:damage,lost_item,broken_equipment,mini_bar,laundry,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        $billing = $additionalCharge->billing;

        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot edit charges on a paid bill.'], 422);
        }

        DB::transaction(function () use ($additionalCharge, $validated, $billing) {
            $additionalCharge->update([
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'category' => $validated['category'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $billing->recalculateTotal();
        });

        return $this->chargesTableResponse($billing);
    }

    /**
     * Remove an additional charge (Billing Panel, AJAX).
     */
    public function destroyAdditionalCharge(AdditionalCharge $additionalCharge)
    {
        $billing = $additionalCharge->billing;

        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot remove charges from a paid bill.'], 422);
        }

        DB::transaction(function () use ($additionalCharge, $billing) {
            $additionalCharge->delete();

            $billing->recalculateTotal();
        });

        return $this->chargesTableResponse($billing);
    }

    /**
     * Re-render the additional charges table fragment with updated totals,
     * used to refresh the Billing Panel after an AJAX charge mutation.
     */
    private function chargesTableResponse(Billing $billing)
    {
        $billing->refresh()->load('additionalCharges');

        return response()->json([
            'html' => view('receptionist.check-out.partials.charges-table', compact('billing'))->render(),
            'running_total' => $billing->running_total,
            'additional_charges_total' => $billing->additional_charges_total,
        ]);
    }
}