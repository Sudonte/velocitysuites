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
     * List reservations pending confirmation.
     */
    public function confirmReservationsIndex(): View
    {
        $reservations = Reservation::with(['guest.user', 'room'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->paginate(15);

        return view('receptionist.reservations.confirm-index', compact('reservations'));
    }

    /**
     * Confirm a pending reservation.
     */
    public function confirmReservation(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'pending') {
            return back()->with('error', 'Only pending reservations can be confirmed.');
        }

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'confirmed']);

            // Update booking status
            if ($reservation->booking) {
                $reservation->booking->update(['booking_status' => 'confirmed']);
            }

            // Mark the room as reserved to prevent double bookings
            $reservation->room->update(['status' => 'reserved']);

            // Notify the guest and managers
            $this->notificationService->notifyReservationConfirmed(
                $reservation->guest->user,
                $reservation->room->room_name
            );
        });

        return redirect()->route('receptionist.reservations.confirm-index')->with('success', 'Reservation confirmed!');
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

            // Make room available again
            $reservation->room->update(['status' => 'available']);

            // Notify the guest about rejection
            Notification::create([
                'user_id' => $reservation->guest->user_id,
                'title' => 'Reservation Rejected',
                'message' => 'Your reservation for ' . $reservation->room->room_name . ' has been rejected. Reason: ' . $request->reason,
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
     * Mark reservation as checked out, generating billing if needed.
     */
    public function checkOut(Reservation $reservation): RedirectResponse
    {
        if ($reservation->status !== 'checked_in') {
            return back()->with('error', 'Only checked-in reservations can be checked out.');
        }

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'checked_out']);

            // Mark the room as available
            $reservation->room->update(['status' => 'available']);

            // Generate billing if it doesn't exist yet
            if ($reservation->booking && !$reservation->booking->billing) {
                $this->generateBilling($reservation);
            }

            // Notify guest and managers
            $this->notificationService->notifyCheckOut(
                $reservation->guest->user,
                $reservation->room->room_name
            );
        });

        if ($reservation->booking && $reservation->booking->fresh()->billing) {
            return redirect()->route('receptionist.billing.show', $reservation->booking->billing)
                ->with('success', 'Guest checked out. Bill generated.');
        }

        return redirect()->route('receptionist.check-out.index')->with('success', 'Guest checked out successfully!');
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
     * List all bills.
     */
    public function billingIndex(Request $request): View
    {
        $query = Billing::with(['booking.reservation.guest.user', 'booking.reservation.room']);

        if ($request->filled('status')) {
            $query->where('billing_status', $request->status);
        }

        $billings = $query->latest()->paginate(15);

        return view('receptionist.billing.index', compact('billings'));
    }

    /**
     * Show a single bill with payment history.
     */
    public function billingShow(Billing $billing): View
    {
        $billing->load(['booking.reservation.guest.user', 'booking.reservation.room', 'payments', 'additionalCharges']);

        $amountPaid = (float) $billing->payments()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');
        $balance = $billing->balance;

        return view('receptionist.billing.show', compact('billing', 'amountPaid', 'balance'));
    }

    /**
     * Record a payment against a billing.
     */
    public function recordPayment(Request $request, Billing $billing): RedirectResponse
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required_if:payment_method,gcash|nullable|string|max:255',
            'amount_paid' => 'required|numeric|min:0.01',
            'payment_status' => 'required|in:completed,pending,failed',
        ]);

        DB::transaction(function () use ($validated, $billing) {
            // Auto-generate reference for cash if blank
            if (empty($validated['reference_number'])) {
                $validated['reference_number'] = 'PAY-' . strtoupper(Str::random(10));
            }

            Payment::create([
                'billing_id' => $billing->id,
                'payment_method' => $validated['payment_method'],
                'reference_number' => $validated['reference_number'],
                'amount_paid' => $validated['amount_paid'],
                'payment_status' => $validated['payment_status'],
                'payment_date' => now(),
            ]);

            // Recompute billing status
            $paid = (float) $billing->payments()
                ->where('payment_status', 'completed')
                ->sum('amount_paid');

            if ($paid >= (float) $billing->total_amount) {
                $billing->update(['billing_status' => 'paid']);
            } elseif ($paid > 0) {
                $billing->update(['billing_status' => 'partial']);
            }

            // Notify guest and managers
            if ($billing->booking && $billing->booking->reservation && $billing->booking->reservation->guest) {
                $guest = $billing->booking->reservation->guest->user;
                $roomName = $billing->booking->reservation->room->room_name;

                // Notify guest about payment
                $this->notificationService->notifyPaymentReceived(
                    $guest,
                    (float) $validated['amount_paid'],
                    $roomName
                );

                // If bill is fully paid, notify guest and managers
                if ($billing->billing_status === 'paid') {
                    $this->notificationService->notifyPaymentComplete($guest);
                } else {
                    // Notify managers about partial payment
                    $this->notificationService->notifyManagerPayment(
                        $guest,
                        (float) $validated['amount_paid'],
                        $billing->billing_status,
                        $roomName
                    );
                }
            }
        });

        return redirect()->route('receptionist.billing.show', $billing)->with('success', 'Payment recorded.');
    }

    /**
     * List all payments.
     */
    public function paymentsIndex(Request $request): View
    {
        $query = Payment::with(['billing.booking.reservation.guest.user', 'billing.booking.reservation.room']);

        if ($request->filled('method')) {
            $query->where('payment_method', $request->method);
        }

        if ($request->filled('status')) {
            $query->where('payment_status', $request->status);
        }

        $payments = $query->latest('payment_date')->paginate(15);

        return view('receptionist.payments.index', compact('payments'));
    }

    /**
     * Generate a billing record for a reservation using the rule from the plan.
     */
    private function generateBilling(Reservation $reservation): Billing
    {
        $nights = max(1, $reservation->check_out->diffInDays($reservation->check_in));

        $roomCharge = (float) $reservation->room->room_rate * $nights;

        $extraGuests = max(0, $reservation->number_of_guests - $reservation->room->room_capacity);
        $extraGuestFee = $extraGuests * (float) config('hotel.extra_guest_fee_rate', 0);

        $amenityCharge = (float) AmenityRequest::where('guest_id', $reservation->guest_id)
            ->where('status', 'approved')
            ->sum(DB::raw('charge * quantity'));

        // Find best applicable active promotion
        $promo = Promotion::where('status', 'active')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->where(function ($q) use ($reservation) {
                $q->whereNull('room_type')
                  ->orWhere('room_type', $reservation->room->room_type);
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
     * Store a new additional charge for a billing.
     */
    public function storeAdditionalCharge(Request $request, Billing $billing): RedirectResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|in:damage,lost_item,broken_equipment,mini_bar,laundry,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Only allow adding charges to pending bills
        if ($billing->billing_status === 'paid') {
            return back()->with('error', 'Cannot add charges to a paid bill.');
        }

        DB::transaction(function () use ($billing, $validated) {
            AdditionalCharge::create([
                'billing_id' => $billing->id,
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'category' => $validated['category'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Recalculate billing total
            $billing->recalculateTotal();
        });

        return back()->with('success', 'Additional charge added successfully.');
    }

    /**
     * Update an existing additional charge.
     */
    public function updateAdditionalCharge(Request $request, AdditionalCharge $additionalCharge): RedirectResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|in:damage,lost_item,broken_equipment,mini_bar,laundry,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        $billing = $additionalCharge->billing;

        // Only allow editing charges on pending bills
        if ($billing->billing_status === 'paid') {
            return back()->with('error', 'Cannot edit charges on a paid bill.');
        }

        DB::transaction(function () use ($additionalCharge, $validated, $billing) {
            $additionalCharge->update([
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'category' => $validated['category'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Recalculate billing total
            $billing->recalculateTotal();
        });

        return back()->with('success', 'Additional charge updated successfully.');
    }

    /**
     * Remove an additional charge.
     */
    public function destroyAdditionalCharge(AdditionalCharge $additionalCharge): RedirectResponse
    {
        $billing = $additionalCharge->billing;

        // Only allow removing charges from pending bills
        if ($billing->billing_status === 'paid') {
            return back()->with('error', 'Cannot remove charges from a paid bill.');
        }

        DB::transaction(function () use ($additionalCharge, $billing) {
            $additionalCharge->delete();

            // Recalculate billing total
            $billing->recalculateTotal();
        });

        return back()->with('success', 'Additional charge removed successfully.');
    }

    /**
     * Confirm the final bill amount before proceeding to payment.
     */
    public function confirmBill(Billing $billing): RedirectResponse
    {
        // Only pending or partial bills can be confirmed
        if (!in_array($billing->billing_status, ['pending', 'partial'])) {
            return back()->with('error', 'This bill cannot be confirmed.');
        }

        // Mark the billing as confirmed
        $billing->update(['billing_status' => 'confirmed']);

        // Notify guest
        if ($billing->booking && $billing->booking->reservation && $billing->booking->reservation->guest) {
            Notification::create([
                'user_id' => $billing->booking->reservation->guest->user_id,
                'title' => 'Bill Confirmed',
                'message' => 'Your bill has been reviewed and confirmed. Total amount: ₱' . number_format($billing->total_amount, 2),
                'category' => 'billing',
            ]);
        }

        return back()->with('success', 'Bill confirmed. Proceed to payment.');
    }
}