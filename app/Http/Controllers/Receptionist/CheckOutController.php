<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Services\NotificationService;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The Check-out Module: two tabs - "Expected Check-outs" (checked-in
 * guests, where check-out starts the Billing/Payment flow) and
 * "Checked-out Guests" (view-only history). Billing and Payment are steps
 * inside check-out (AJAX modals), not standalone pages.
 */
class CheckOutController extends Controller
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    public function index(Request $request): View
    {
        $tab = $request->get('tab', 'expected');
        if (!in_array($tab, ['expected', 'checked_out'])) {
            $tab = 'expected';
        }

        $bookings = Booking::with(['reservation.guest.user', 'guest.user', 'rooms', 'roomType', 'billing'])
            ->where('booking_status', $tab === 'expected' ? 'checked_in' : 'checked_out')
            ->orderBy('check_out', $tab === 'expected' ? 'asc' : 'desc')
            ->paginate(15)
            ->withQueryString();

        $expectedCount = Booking::where('booking_status', 'checked_in')->count();
        $checkedOutCount = Booking::where('booking_status', 'checked_out')->count();

        return view('receptionist.check-out.index', compact('bookings', 'tab', 'expectedCount', 'checkedOutCount'));
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

        $booking->load(['reservation.guest.user', 'guest.user', 'rooms']);
        $billing->load(['additionalCharges', 'discountApplied']);

        $amenityRequests = AmenityRequest::with('amenity')
            ->where($booking->reservation_id ? 'reservation_id' : 'booking_id', $booking->reservation_id ?? $booking->id)
            ->where('status', 'approved')
            ->get();

        $discounts = Discount::where('status', 'active')->orderBy('name')->get();

        return view('receptionist.check-out.partials.billing-panel', compact('booking', 'billing', 'amenityRequests', 'discounts'));
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
        $billing->load(['booking.reservation.guest.user', 'booking.rooms', 'payments', 'additionalCharges', 'discountApplied']);

        $balance = $billing->balance;
        $amountPaidSoFar = (float) $billing->payments()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');

        return view('receptionist.check-out.partials.payment-panel', compact('billing', 'balance', 'amountPaidSoFar'));
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

            $guest = $booking->account_guest?->user;
            $rooms = $booking->rooms->isNotEmpty() ? $booking->rooms : collect([$booking->room])->filter();
            $roomName = $rooms->pluck('room_name')->implode(', ');

            if ($guest) {
                $this->notificationService->notifyPaymentReceived(
                    $guest,
                    (float) $validated['amount_paid'],
                    $roomName,
                    $booking->reservation_id ?? $booking->id
                );
            }

            Activity::log(
                'Recorded payment',
                "Booking #{$booking->id} - ₱" . number_format((float) $validated['amount_paid'], 2) . " ({$validated['payment_method']}) from " . ($guest->full_name ?? $booking->stay_guest_full_name ?? 'guest'),
                $booking
            );

            if ($completed) {
                $booking->update(['booking_status' => 'checked_out']);
                foreach ($rooms as $room) {
                    $room->update(['status' => 'available']);
                }

                if ($guest) {
                    $this->notificationService->notifyCheckOut($guest, $roomName, $booking->reservation_id ?? $booking->id);
                    $this->notificationService->notifyPaymentComplete($guest, $booking->reservation_id ?? $booking->id);
                }

                Activity::log(
                    'Checked out guest',
                    "Booking #{$booking->id} - " . ($guest->full_name ?? $booking->stay_guest_full_name ?? 'guest') . " from {$roomName}",
                    $booking
                );
            } else {
                if ($guest) {
                    $this->notificationService->notifyManagerPayment(
                        $guest,
                        (float) $validated['amount_paid'],
                        $billing->billing_status,
                        $roomName,
                        $booking->reservation_id ?? $booking->id
                    );
                }
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
     * Apply a manually-selected, ID-verified authorized discount (Senior
     * Citizen, PWD, Student, etc.) to a draft billing. Only reachable when
     * the guest actually requested one at reservation time - a receptionist
     * never picks a discount out of thin air. Fully separate from
     * Promotions: this only ever touches Discount/billings.discount_id,
     * never a Promotion record.
     */
    public function applyDiscount(Request $request, Billing $billing)
    {
        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot change the discount on a paid bill.'], 422);
        }

        // A reservation-derived booking's discount request lives on its
        // Reservation; a direct "New Booking" transaction (no reservation
        // at all) carries the exact same fields directly on the Booking
        // itself instead (see the discount_requested/discount_verification_status
        // migration mirroring reservations' equivalent columns).
        $booking = $billing->booking;
        $discountTarget = $booking->reservation ?? $booking;
        if (!$discountTarget->discount_requested) {
            return response()->json(['message' => 'This guest did not request a discount.'], 422);
        }

        $validated = $request->validate([
            'discount_id' => 'required|exists:discounts,id',
        ]);

        $discount = Discount::where('status', 'active')->findOrFail($validated['discount_id']);

        DB::transaction(function () use ($billing, $discount, $discountTarget) {
            $subtotal = (float) $billing->room_charge
                + (float) $billing->additional_guest_fee
                + (float) $billing->amenity_charge
                + $billing->additional_charges_total;

            $amount = $discount->discount_type === 'percentage'
                ? $subtotal * ((float) $discount->value / 100)
                : (float) $discount->value;

            $billing->update([
                'discount' => round(min($amount, $subtotal), 2),
                'discount_id' => $discount->id,
                'discount_verified_by' => auth()->id(),
                'discount_verified_at' => now(),
            ]);
            $billing->recalculateTotal();

            if ($discountTarget->discount_verification_status !== 'approved') {
                $discountTarget->update(['discount_verification_status' => 'approved']);
            }
        });

        $billing->refresh()->load('discountApplied');
        $discounts = Discount::where('status', 'active')->orderBy('name')->get();

        return response()->json([
            'html' => view('receptionist.check-out.partials.discount-panel', compact('billing', 'discounts'))->render(),
            'running_total' => $billing->running_total,
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

        $billing->refresh()->loadMissing('booking.account_guest.user');
        Activity::log(
            'Recorded additional charge',
            "Billing #{$billing->id} - {$validated['description']} (₱" . number_format((float) $validated['amount'], 2) . ')',
            $billing->booking ?? $billing
        );
        if ($guest = $billing->booking?->account_guest?->user) {
            $this->notificationService->notifyAdditionalCharge(
                $guest,
                $validated['description'],
                (float) $validated['amount'],
                $billing->balance,
                $billing->booking->id
            );
        }

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

        Activity::log(
            'Updated additional charge',
            "Billing #{$billing->id} - {$validated['description']} (₱" . number_format((float) $validated['amount'], 2) . ')',
            $billing->booking ?? $billing
        );

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

        $description = $additionalCharge->description;
        $amount = (float) $additionalCharge->amount;

        DB::transaction(function () use ($additionalCharge, $billing) {
            $additionalCharge->delete();

            $billing->recalculateTotal();
        });

        Activity::log(
            'Removed additional charge',
            "Billing #{$billing->id} - {$description} (₱" . number_format($amount, 2) . ')',
            $billing->booking ?? $billing
        );

        return $this->chargesTableResponse($billing);
    }

    /**
     * Generate a billing record for a booking. Any already-verified deposit
     * payment made at reservation time (see
     * ReservationWorkflowService::convertToBooking()) is re-parented onto
     * this billing so it counts toward the balance immediately - the
     * checkout Billing/Payment Panels need no other changes to reflect it.
     */
    private function generateBilling(Booking $booking): Billing
    {
        $reservation = $booking->reservation; // null for a direct "New Booking" transaction
        $nights = max(1, abs($booking->check_out->diffInDays($booking->check_in)));

        // A multi-room booking's rooms may each have their own rate
        // override, so this sums per-room rather than multiplying a single
        // rate by rooms_requested. Falls back to the legacy single room()
        // relation for the rare pre-migration booking with no pivot rows.
        $rooms = $booking->rooms->isNotEmpty() ? $booking->rooms : collect([$booking->room])->filter();
        $roomCharge = $rooms->sum(fn ($room) => (float) $room->room_rate) * $nights;

        // Children under 12 stay free - only adults count toward the
        // extra-guest fee, measured against the combined capacity of every
        // assigned room, not just one.
        $adults = $booking->adults ?? $booking->number_of_guests;
        $totalCapacity = $rooms->sum('room_capacity');
        $extraGuests = max(0, $adults - $totalCapacity);
        $extraGuestFee = $extraGuests * (float) config('hotel.extra_guest_fee_rate', 0);

        // Covers both a reservation-derived booking's amenity requests
        // (reservation_id) and a direct booking's own (booking_id) -
        // whichever applies. Branched explicitly rather than relying on
        // where('reservation_id', null) matching semantics.
        $amenityCharge = (float) AmenityRequest::where(function ($q) use ($booking) {
                if ($booking->reservation_id) {
                    $q->where('reservation_id', $booking->reservation_id);
                } else {
                    $q->where('booking_id', $booking->id);
                }
            })
            ->where('status', 'approved')
            ->sum(DB::raw('charge * quantity'));

        // No automatic discount - Promotions are package/amenity-only now
        // (their inclusions are zero-charge amenity requests granted at
        // conversion time, handled separately above via $amenityCharge).
        // Authorized discounts (Senior Citizen, PWD, etc.) are applied
        // manually here by the receptionist after verifying the guest's
        // uploaded ID, via applyDiscount() above.
        $discount = 0;

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

        // Re-parent any already-completed pre-checkout payment onto this
        // billing so it counts toward the balance immediately. A
        // reservation-derived booking's payment was a "deposit" stage
        // (paid at reservation time); a direct booking's was already
        // "final" stage (paid in full at booking time, see
        // DirectBookingService::create()) - there's no deposit concept for
        // that transaction type, so no stage filter is needed there.
        if ($reservation) {
            $reservation->payments()
                ->where('payment_stage', 'deposit')
                ->where('payment_status', 'completed')
                ->whereNull('billing_id')
                ->update(['billing_id' => $billing->id]);
        } else {
            $booking->payments()
                ->where('payment_status', 'completed')
                ->whereNull('billing_id')
                ->update(['billing_id' => $billing->id]);
        }

        $paid = (float) $billing->payments()->where('payment_status', 'completed')->sum('amount_paid');
        if ($paid > 0) {
            $billing->update(['billing_status' => $paid >= (float) $billing->total_amount ? 'paid' : 'partial']);
        }

        return $billing;
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
