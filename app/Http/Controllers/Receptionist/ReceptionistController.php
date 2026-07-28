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
use App\Services\NotificationService;
use App\Services\RoomAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReceptionistController extends Controller
{
    protected NotificationService $notificationService;
    protected RoomAvailabilityService $availability;

    public function __construct(NotificationService $notificationService, RoomAvailabilityService $availability)
    {
        $this->notificationService = $notificationService;
        $this->availability = $availability;
    }

    /**
     * Display the receptionist dashboard.
     */
    public function dashboard(): View
    {
        // Status-driven counts that mirror the actual work queues, so any
        // action (accept, convert, check-in, check-out) moves these
        // immediately - check-in/check-out are no longer date-gated.
        $availableRooms = Room::where('status', 'available')->count();
        $bookingRequests = Reservation::whereIn('status', ['pending_review', 'ready_for_booking'])->count();
        $awaitingCheckIn = Booking::where('booking_status', 'confirmed')->count();
        $inHouseGuests = Booking::where('booking_status', 'checked_in')->count();

        // Today's schedule stays date-based - it's a schedule. Arrivals due
        // today (not yet checked in) and departures due today (still in house).
        $todayArrivals = Booking::with(['reservation.guest.user', 'room', 'roomType'])
            ->whereDate('check_in', today())
            ->where('booking_status', 'confirmed')
            ->get();

        $todayDepartures = Booking::with(['reservation.guest.user', 'room'])
            ->whereDate('check_out', today())
            ->where('booking_status', 'checked_in')
            ->get();

        return view('receptionist.dashboard', compact(
            'availableRooms',
            'bookingRequests',
            'awaitingCheckIn',
            'inHouseGuests',
            'todayArrivals',
            'todayDepartures'
        ));
    }

    /**
     * List reservations awaiting check-in: every confirmed booking,
     * regardless of its scheduled date - early arrivals can be checked in
     * whenever their room is actually ready (room status is the real gate).
     *
     * NOTE: this still uses the pre-redesign single-list UI. The dedicated
     * "Expected Check-ins" / "Checked-in Guests" tabs and check-in-time room
     * assignment are built in the Check-in Module phase - this keeps
     * check-in functionally correct against the new Booking-based model in
     * the meantime.
     */
    public function checkInIndex(): View
    {
        $bookings = Booking::with(['reservation.guest.user', 'room', 'roomType'])
            ->where('booking_status', 'confirmed')
            ->orderBy('check_in')
            ->paginate(15);

        // Room assignment happens here, at check-in, per the redesigned
        // workflow. NOTE: this list/assign-inline UI is a minimal stand-in
        // for the full "Expected Check-ins" / "Checked-in Guests" tabs the
        // Check-in Module phase builds - kept simple for now so the system
        // isn't left unable to assign rooms at all in the meantime.
        $assignableRooms = $bookings->getCollection()
            ->filter(fn (Booking $booking) => !$booking->room)
            ->mapWithKeys(fn (Booking $booking) => [$booking->id => $this->availability->assignableRooms($booking)]);

        return view('receptionist.check-in.index', compact('bookings', 'assignableRooms'));
    }

    /**
     * Assign a room to an unassigned confirmed booking. The room must
     * actually be free for the booking's dates (not just "available"
     * status) - reuses the same assignableRooms() query the check-in list
     * used to populate the dropdown, so a stale/concurrent selection can't
     * double-book a room.
     */
    public function assignRoom(Request $request, Booking $booking): RedirectResponse
    {
        if ($booking->booking_status !== 'confirmed') {
            return back()->with('error', 'Only confirmed bookings can have a room assigned.');
        }

        $validated = $request->validate(['room_id' => 'required|exists:rooms,id']);

        $room = $this->availability->assignableRooms($booking)->firstWhere('id', (int) $validated['room_id']);

        if (!$room) {
            return back()->with('error', 'That room cannot be assigned: it is not an available ' . $booking->roomType->name . ' room for these dates.');
        }

        $booking->update(['room_id' => $room->id]);

        return back()->with('success', 'Room ' . $room->room_number . ' assigned.');
    }

    /**
     * Mark booking as checked in. Date is not a gate - the room's actual
     * status is: a room still occupied by the previous guest or under
     * maintenance can't receive a new check-in.
     */
    public function checkIn(Booking $booking): RedirectResponse
    {
        if ($booking->booking_status !== 'confirmed') {
            return back()->with('error', 'Only confirmed bookings can be checked in.');
        }

        if (!$booking->room) {
            return back()->with('error', 'Assign a room to this booking before checking in.');
        }

        if (in_array($booking->room->status, ['occupied', 'maintenance'])) {
            return back()->with('error',
                'Room ' . $booking->room->room_number . ' is not ready (' . $booking->room->status . '). ' .
                'Free it up first or assign a different room.');
        }

        DB::transaction(function () use ($booking) {
            $booking->update(['booking_status' => 'checked_in']);
            $booking->room->update(['status' => 'occupied']);

            $this->notificationService->notifyCheckIn(
                $booking->reservation->guest->user,
                $booking->room->room_name
            );
        });

        return redirect()->route('receptionist.check-in.index')->with('success', 'Guest checked in successfully!');
    }

    /**
     * List bookings available for check-out: every checked-in guest,
     * regardless of scheduled departure date - a guest can leave early
     * (or late) whenever they settle their bill.
     *
     * NOTE: pre-redesign single-list UI, same caveat as checkInIndex().
     */
    public function checkOutIndex(): View
    {
        $bookings = Booking::with(['reservation.guest.user', 'room', 'billing'])
            ->where('booking_status', 'checked_in')
            ->orderBy('check_out')
            ->paginate(15);

        return view('receptionist.check-out.index', compact('bookings'));
    }

    /**
     * Open (or resume) the Billing Panel for a check-out in progress.
     * Creates a draft billing if one doesn't exist yet; does not change
     * booking or room status.
     */
    public function checkOutBilling(Booking $booking)
    {
        if ($booking->booking_status !== 'checked_in') {
            return response()->json(['message' => 'Only checked-in bookings can be billed.'], 422);
        }

        $billing = $booking->billing ?? $this->generateBilling($booking);

        $booking->load(['reservation.guest.user', 'room']);
        $billing->load('additionalCharges');

        $amenityRequests = AmenityRequest::with('amenity')
            ->where('reservation_id', $booking->reservation_id)
            ->where('status', 'approved')
            ->get();

        return view('receptionist.check-out.partials.billing-panel', compact('booking', 'billing', 'amenityRequests'));
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
        $billing->load(['booking.reservation.guest.user', 'booking.room', 'payments', 'additionalCharges']);

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
     * Show a read-only receipt for a bill (reachable from the reservation page,
     * not from a standalone Billing list).
     */
    public function receiptShow(Billing $billing): View
    {
        $billing->load(['booking.reservation.guest.user', 'booking.room', 'payments', 'additionalCharges']);

        $amountPaid = (float) $billing->payments()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');
        $balance = $billing->balance;

        return view('receptionist.billing.receipt', compact('billing', 'amountPaid', 'balance'));
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

        $booking = $billing->booking;

        if (!$booking || $booking->booking_status !== 'checked_in') {
            return response()->json(['message' => 'This booking is not awaiting checkout.'], 422);
        }

        $completed = false;

        DB::transaction(function () use ($validated, $billing, $booking, &$completed) {
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
                'payment_stage' => 'final',
                'payment_date' => now(),
            ]);

            $paid = (float) $billing->payments()
                ->where('payment_status', 'completed')
                ->sum('amount_paid');

            $completed = $paid >= (float) $billing->total_amount;
            $billing->update(['billing_status' => $completed ? 'paid' : 'partial']);

            $guest = $booking->reservation->guest->user;
            $roomName = $booking->room->room_name;

            $this->notificationService->notifyPaymentReceived(
                $guest,
                (float) $validated['amount_paid'],
                $roomName
            );

            if ($completed) {
                $booking->update(['booking_status' => 'checked_out']);
                $booking->room->update(['status' => 'available']);

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
     * Generate a billing record for a booking using the rule from the plan.
     * Any already-verified deposit payment made at reservation time (see
     * ReservationWorkflowService::convertToBooking()) is re-parented onto
     * this billing so it counts toward the balance immediately - the
     * checkout Billing/Payment Panels need no other changes to reflect it.
     */
    private function generateBilling(Booking $booking): Billing
    {
        $reservation = $booking->reservation;
        $nights = max(1, abs($booking->check_out->diffInDays($booking->check_in)));

        $roomCharge = (float) $booking->room->room_rate * $nights;

        // Children under 12 stay free - only adults count toward the
        // extra-guest fee, even though both occupy the room's capacity.
        $adults = $booking->adults ?? $booking->number_of_guests;
        $extraGuests = max(0, $adults - $booking->room->room_capacity);
        $extraGuestFee = $extraGuests * (float) config('hotel.extra_guest_fee_rate', 0);

        $amenityCharge = (float) AmenityRequest::where('reservation_id', $booking->reservation_id)
            ->where('status', 'approved')
            ->sum(DB::raw('charge * quantity'));

        // Find best applicable active DISCOUNT promotion (amenity promos
        // don't reduce the rate - their inclusions are zero-charge
        // amenity requests granted at conversion time). Removed once the
        // Discount Module phase separates guest-verified discounts from
        // promotions entirely.
        $promo = Promotion::where('status', 'active')
            ->where('promo_type', 'discount')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->where(function ($q) use ($booking) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $booking->room_type_id);
            })
            ->orderByDesc('discount_value')
            ->first();

        $discount = 0;
        if ($promo) {
            $discount = $promo->discount_type === 'percentage'
                ? round(($roomCharge * (float) $promo->discount_value) / 100, 2)
                : (float) $promo->discount_value;
        }
        // Never discount more than the room charge
        $discount = min($discount, $roomCharge);

        $total = max(0, $roomCharge + $extraGuestFee + $amenityCharge - $discount);

        $billing = Billing::create([
            'booking_id' => $booking->id,
            'room_charge' => round($roomCharge, 2),
            'additional_guest_fee' => round($extraGuestFee, 2),
            'amenity_charge' => round($amenityCharge, 2),
            'discount' => round($discount, 2),
            'total_amount' => round($total, 2),
            'billing_status' => 'pending',
        ]);

        $reservation->payments()
            ->where('payment_stage', 'deposit')
            ->where('payment_status', 'completed')
            ->whereNull('billing_id')
            ->update(['billing_id' => $billing->id]);

        $paid = (float) $billing->payments()->where('payment_status', 'completed')->sum('amount_paid');
        if ($paid > 0) {
            $billing->update(['billing_status' => $paid >= (float) $billing->total_amount ? 'paid' : 'partial']);
        }

        return $billing;
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