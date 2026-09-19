<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single place where a Reservation turns into a paid Booking, used by
 * every path that can do that: the guest self-service "Book & Pay" flow
 * (web + API), a receptionist converting an existing Reservation or
 * creating one on behalf of a walk-in, and checkout billing for guests
 * who never pre-paid. Booking/Billing rows are created lazily here, not
 * alongside every Reservation - see Guest\ReservationController@store /
 * Api\ReservationController@store, which create a plain Reservation and
 * nothing else.
 */
class BookingService
{
    /**
     * Room charge for the full stay (room type rate x nights x
     * rooms_requested) minus the best applicable active discount promotion, minus the senior-
     * citizen/PWD statutory 20% discount if the reservation has one
     * (id_card_type, mobile-app-only field - see Api\ReservationController
     * @store). The two are additive, capped at the room charge - they
     * were never combined before this unification, so stacking rather
     * than picking one is the conservative choice (doesn't reduce any
     * discount a guest would previously have gotten via either path).
     * Centralizes a calculation that used to be duplicated between
     * Guest\ReservationController@create's preview,
     * Receptionist\ReceptionistController::generateBilling, and
     * Api\PaymentController's ad-hoc ID-card discount.
     */
    public function quoteRoomCharge(Reservation $reservation): array
    {
        $roomType = $reservation->roomType;
        $nights = max(1, abs($reservation->check_out->diffInDays($reservation->check_in)));

        // Per-room-type-line lines when this is a genuine multi-room-type
        // reservation (real reservation_room_lines - see
        // Api\ReservationController::store()/update()), else a single
        // synthetic line matching the legacy roomType/rooms_requested
        // fields - unifies both cases into the one discount loop below.
        // Using only roomType/rooms_requested for room_charge itself would
        // price every line at the FIRST line's rate times the SUM of every
        // line's quantity, wrong the moment more than one room type is
        // involved (the same bug already fixed on the Booking side - see
        // Booking::getTotalAmountDueAttribute()).
        $lines = ! empty($reservation->room_lines)
            ? collect($reservation->room_lines)->map(fn (array $l) => [
                'room_type_id' => (int) $l['room_type_id'],
                'subtotal' => (float) $l['subtotal'],
            ])
            : collect([[
                'room_type_id' => $roomType->id,
                'subtotal' => (float) $roomType->rate * $nights * max(1, $reservation->rooms_requested),
            ]]);

        $roomCharge = (float) $lines->sum('subtotal');

        // A discount-type promo is matched and computed PER LINE, against
        // that line's own room type and own subtotal - a promo scoped to
        // one room type must never discount a different room type's
        // charge (matching the FIRST line's type alone would either
        // over-discount an unrelated line, or silently skip a genuinely
        // eligible line that isn't first). Percentage discounts scale
        // naturally per line; a fixed-amount promo is applied only once
        // per promo across the whole reservation (not once per matching
        // line), so a flat "₱500 off" site-wide promo can't become ₱1000+
        // off just because it matches two different room-type lines.
        $promoDiscount = 0;
        $appliedFixedPromoIds = [];
        foreach ($lines as $line) {
            $lineRoomType = RoomType::find($line['room_type_id']);
            if (! $lineRoomType) {
                continue;
            }

            $promo = Promotion::where('status', 'active')
                ->where('promo_type', 'discount')
                ->whereDate('start_date', '<=', today())
                ->whereDate('end_date', '>=', today())
                ->where(function ($q) use ($lineRoomType) {
                    $q->whereNull('room_type_id')
                      ->orWhere('room_type_id', $lineRoomType->id);
                })
                ->orderByDesc('discount_value')
                ->first();

            if (! $promo) {
                continue;
            }

            if ($promo->discount_type === 'percentage') {
                $promoDiscount += min(round($line['subtotal'] * (float) $promo->discount_value / 100, 2), $line['subtotal']);
            } elseif (! in_array($promo->id, $appliedFixedPromoIds, true)) {
                $promoDiscount += min((float) $promo->discount_value, $line['subtotal']);
                $appliedFixedPromoIds[] = $promo->id;
            }
        }

        $discount = $promoDiscount;

        if (in_array($reservation->id_card_type, ['Senior Citizen', 'PWD'], true)) {
            $discount += round($roomCharge * 0.20, 2);
        }

        $discount = min($discount, $roomCharge);

        return [
            'nights' => $nights,
            'room_charge' => round($roomCharge, 2),
            'discount' => round($discount, 2),
            'total' => round(max(0, $roomCharge - $discount), 2),
        ];
    }

    /**
     * Ensure a Booking row exists for the reservation (idempotent). A
     * plain Reserve has none until this is called - explicitly when a
     * guest/staff pays, or implicitly at checkout for a guest who never
     * pre-paid (see Receptionist\ReceptionistController::generateBilling).
     *
     * Creating a Booking is what "conversion" means under the current
     * Reservation/Booking split (see ReservationWorkflowService) - a
     * Booking only ever exists for a converted reservation, and Booking
     * carries its own copy of room_type/dates/guest counts taken here
     * (Reservation is frozen/historical afterward). $bookingStatus of
     * 'pending' is coerced to 'confirmed' - the new booking_status enum
     * has no 'pending' member, since a Booking never exists before that
     * point.
     */
    public function ensureBooking(Reservation $reservation, string $bookingStatus = Booking::STATUS_ACTIVE): Booking
    {
        if ($reservation->booking) {
            return $reservation->booking;
        }

        $booking = Booking::create([
            'reservation_id' => $reservation->id,
            'room_type_id' => $reservation->room_type_id,
            'check_in' => $reservation->check_in,
            'check_out' => $reservation->check_out,
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'number_of_guests' => $reservation->number_of_guests,
            'confirmed_at' => now(),
            'booking_status' => $bookingStatus === 'pending' ? Booking::STATUS_ACTIVE : $bookingStatus,
        ]);

        if ($reservation->status !== Reservation::STATUS_CONVERTED) {
            $reservation->update(['status' => Reservation::STATUS_CONVERTED]);
        }

        return $booking;
    }

    /**
     * Ensure a Billing row exists, seeded with the room charge/discount/
     * amenity charge locked in at the time it's first created. Deliberately
     * does NOT overwrite room_charge/discount on an existing Billing (see
     * applyStayCharges for what does get refreshed) - a guest who
     * pre-paid keeps the rate/discount they were quoted even if a
     * promotion expires before checkout.
     *
     * amenity_charge is seeded from the reservation's own bookingAmenities
     * (ReservationAmenity - the frozen, creation-time snapshot of every
     * paid amenity the guest already selected and was charged for) rather
     * than left at its column default of 0 - previously this method never
     * set amenity_charge at all, so total_amount was room-charge-only from
     * conversion until the guest actually checked out (applyStayCharges()
     * is the only other place that sets it, and that only ever runs at
     * checkout) - every screen reading this Billing row in between showed
     * a grand total silently missing the guest's own paid amenities.
     * total_amount itself is computed via recalculateTotal() (the same
     * single formula applyStayCharges() relies on later) rather than
     * duplicated here, so the two can never disagree.
     */
    public function ensureBilling(Booking $booking, Reservation $reservation): Billing
    {
        if ($booking->billing) {
            return $booking->billing;
        }

        $quote = $this->quoteRoomCharge($reservation);
        $amenityCharge = round((float) $reservation->bookingAmenities->sum('subtotal'), 2);

        $billing = Billing::create([
            'booking_id' => $booking->id,
            'room_charge' => $quote['room_charge'],
            'amenity_charge' => $amenityCharge,
            'discount' => $quote['discount'],
            'total_amount' => 0,
            'billing_status' => 'pending',
        ]);
        $billing->recalculateTotal();

        return $billing;
    }

    /**
     * Applies charges only knowable once the stay is underway (extra-
     * guest fee, approved amenity requests) on top of whatever
     * room_charge/discount the billing already has. Safe to call
     * whether the billing was just created fresh (guest never pre-paid)
     * or already existed (guest paid via "Book & Pay" before arrival) -
     * called at every checkout billing open.
     */
    public function applyStayCharges(Billing $billing, Reservation $reservation): void
    {
        // Children under 12 stay free - only adults count toward the
        // extra-guest fee, even though both occupy the room's capacity.
        $adults = $reservation->adults ?? $reservation->number_of_guests;
        $roomCapacity = $reservation->room->room_capacity ?? $reservation->roomType->capacity;
        $extraGuests = max(0, $adults - $roomCapacity);
        $extraGuestFee = $extraGuests * (float) config('hotel.extra_guest_fee_rate', 0);

        $amenityCharge = (float) AmenityRequest::where('reservation_id', $reservation->id)
            ->where('status', 'approved')
            ->sum(DB::raw('charge * quantity'));

        $billing->update([
            'additional_guest_fee' => round($extraGuestFee, 2),
            'amenity_charge' => round($amenityCharge, 2),
        ]);
        $billing->recalculateTotal();
    }

    /**
     * Record a payment against a reservation, creating the Booking/
     * Billing first if they don't exist yet.
     *
     * $staffRecorded=true: staff directly verified cash/GCash in person
     * (walk-in booking, receptionist-converted reservation, checkout) -
     * payment lands 'completed' and the booking 'confirmed' immediately.
     *
     * $staffRecorded=false: guest self-service (app or website) - there's
     * no real payment gateway to verify a manually-typed GCash reference
     * against, so both stay 'pending' until a receptionist verifies it
     * (see verifyPayment()).
     */
    public function recordPayment(Reservation $reservation, array $paymentData, bool $staffRecorded): Payment
    {
        return DB::transaction(function () use ($reservation, $paymentData, $staffRecorded) {
            $booking = $this->ensureBooking($reservation, $staffRecorded ? Booking::STATUS_ACTIVE : 'pending');
            $billing = $this->ensureBilling($booking, $reservation);

            $referenceNumber = $paymentData['reference_number'] ?? null;
            if (empty($referenceNumber)) {
                $referenceNumber = 'PAY-' . strtoupper(Str::random(10));
            }

            $payment = Payment::create([
                'billing_id' => $billing->id,
                'payment_method' => $paymentData['payment_method'],
                'reference_number' => $referenceNumber,
                'amount_paid' => $paymentData['amount_paid'],
                'payment_status' => $staffRecorded ? 'completed' : 'pending',
                'payment_date' => now(),
            ]);

            if ($staffRecorded) {
                $this->recalculateBillingStatus($billing);
                if ($booking->booking_status !== Booking::STATUS_ACTIVE) {
                    $booking->update(['booking_status' => Booking::STATUS_ACTIVE]);
                }
            }

            return $payment;
        });
    }

    /**
     * Approve a guest-self-submitted pending payment: flips it to
     * completed, recalculates the billing status from verified payments,
     * and confirms the booking. Used by the receptionist "verify
     * payments" queue - the guest-self-service counterpart to staff
     * directly recording a payment.
     */
    public function verifyPayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment->update(['payment_status' => 'completed']);

            $billing = $payment->billing;
            $this->recalculateBillingStatus($billing);

            $booking = $billing->booking;
            if ($booking && $booking->booking_status !== Booking::STATUS_ACTIVE) {
                $booking->update(['booking_status' => Booking::STATUS_ACTIVE]);
            }
        });
    }

    public function recalculateBillingStatus(Billing $billing): void
    {
        $paid = (float) $billing->payments()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');

        $billing->update([
            'billing_status' => $paid >= (float) $billing->total_amount ? 'paid' : ($paid > 0 ? 'partial' : 'pending'),
        ]);
    }
}
