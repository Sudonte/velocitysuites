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
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReceptionistController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Display the receptionist dashboard.
     */
    public function dashboard(): View
    {
        $availableRooms = Room::where('status', 'available')->count();
        $todayCheckIns = Reservation::whereDate('check_in', today())
            ->where('status', 'confirmed')
            ->count();
        $todayCheckOuts = Reservation::whereDate('check_out', today())
            ->where('status', 'checked_in')
            ->count();
        $currentReservations = Reservation::whereIn('status', ['confirmed', 'checked_in'])->count();
        $pendingArrivals = Reservation::where('status', 'confirmed')
            ->whereDate('check_in', '>=', today())
            ->count();

        // Today's schedule: confirmations arriving and in-house guests leaving today
        $todayArrivals = Reservation::with(['guest.user', 'room'])
            ->whereDate('check_in', today())
            ->where('status', 'confirmed')
            ->get();

        $todayDepartures = Reservation::with(['guest.user', 'room'])
            ->whereDate('check_out', today())
            ->where('status', 'checked_in')
            ->get();

        return view('receptionist.dashboard', compact(
            'availableRooms',
            'todayCheckIns',
            'todayCheckOuts',
            'currentReservations',
            'pendingArrivals',
            'todayArrivals',
            'todayDepartures'
        ));
    }

    /**
     * List all reservations (read-only).
     */
    public function reservationsIndex(Request $request): View
    {
        $query = Reservation::with(['guest.user', 'room', 'booking.billing']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('guest.user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhereHas('room', function ($q) use ($search) {
                $q->where('room_number', 'like', "%{$search}%");
            });
        }

        $reservations = $query->latest('check_in')->paginate(15);

        return view('receptionist.reservations.index', compact('reservations'));
    }

    /**
     * Show a reservation (read-only).
     */
    public function reservationShow(Reservation $reservation): View
    {
        $reservation->load(['guest.user', 'room', 'booking.billing.payments', 'amenityRequests.amenity']);

        return view('receptionist.reservations.show', compact('reservation'));
    }

    /**
     * List booking requests awaiting room assignment and confirmation.
     * Each pending reservation gets a dropdown of rooms of the requested
     * type that are actually free for the requested dates.
     */
    public function confirmReservationsIndex(): View
    {
        $reservations = Reservation::with(['guest.user', 'roomType'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->paginate(15);

        $assignableRooms = $reservations->getCollection()->mapWithKeys(function ($reservation) {
            return [$reservation->id => $this->assignableRoomsFor($reservation)];
        });

        return view('receptionist.reservations.confirm-index', compact('reservations', 'assignableRooms'));
    }

    /**
     * Rooms of the reservation's requested type that can be assigned to it:
     * in service, currently available, and with no confirmed/checked-in
     * reservation overlapping the requested dates.
     */
    private function assignableRoomsFor(Reservation $reservation)
    {
        return Room::where('room_type_id', $reservation->room_type_id)
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

            // Notify the guest with their assigned room
            $this->notificationService->notifyReservationConfirmed(
                $reservation->guest->user,
                $room->room_name . ' (Room ' . $room->room_number . ')'
            );
        });

        return redirect()->route('receptionist.reservations.confirm-index')
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

        return redirect()->route('receptionist.reservations.confirm-index')->with('success', 'Reservation rejected.');
    }

    /**
     * List reservations pending check-in (confirmed with check_in <= today).
     */
    public function checkInIndex(): View
    {
        $reservations = Reservation::with(['guest.user', 'room'])
            ->where('status', 'confirmed')
            ->whereDate('check_in', '<=', today())
            ->orderBy('check_in')
            ->paginate(15);

        return view('receptionist.check-in.index', compact('reservations'));
    }

    /**
     * Mark reservation as checked in.
     */
    public function checkIn(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'confirmed') {
            return back()->with('error', 'Only confirmed reservations can be checked in.');
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
     * List reservations pending check-out (checked_in with check_out <= today).
     */
    public function checkOutIndex(): View
    {
        $reservations = Reservation::with(['guest.user', 'room', 'booking.billing'])
            ->where('status', 'checked_in')
            ->whereDate('check_out', '<=', today())
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

        if (!$reservation->booking) {
            return response()->json(['message' => 'This reservation has no booking record.'], 422);
        }

        $billing = $reservation->booking->billing ?? $this->generateBilling($reservation);

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
     * Show a read-only receipt for a bill (reachable from the reservation page,
     * not from a standalone Billing list).
     */
    public function receiptShow(Billing $billing): View
    {
        $billing->load(['booking.reservation.guest.user', 'booking.reservation.room', 'payments', 'additionalCharges']);

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

            $paid = (float) $billing->payments()
                ->where('payment_status', 'completed')
                ->sum('amount_paid');

            $completed = $paid >= (float) $billing->total_amount;
            $billing->update(['billing_status' => $completed ? 'paid' : 'partial']);

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
     * Generate a billing record for a reservation using the rule from the plan.
     */
    private function generateBilling(Reservation $reservation): Billing
    {
        $nights = max(1, abs($reservation->check_out->diffInDays($reservation->check_in)));

        $roomCharge = (float) $reservation->room->room_rate * $nights;

        $extraGuests = max(0, $reservation->number_of_guests - $reservation->room->room_capacity);
        $extraGuestFee = $extraGuests * (float) config('hotel.extra_guest_fee_rate', 0);

        $amenityCharge = (float) AmenityRequest::where('reservation_id', $reservation->id)
            ->where('status', 'approved')
            ->sum(DB::raw('charge * quantity'));

        // Find best applicable active promotion
        $promo = Promotion::where('status', 'active')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->where(function ($q) use ($reservation) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $reservation->room_type_id);
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

        return Billing::create([
            'booking_id' => $reservation->booking->id,
            'room_charge' => round($roomCharge, 2),
            'additional_guest_fee' => round($extraGuestFee, 2),
            'amenity_charge' => round($amenityCharge, 2),
            'discount' => round($discount, 2),
            'total_amount' => round($total, 2),
            'billing_status' => 'pending',
        ]);
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