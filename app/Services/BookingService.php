<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
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
     * Room charge for the full stay (room type rate x nights) minus the
     * best applicable active discount promotion, minus the senior-
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
        $roomCharge = (float) $roomType->rate * $nights;

        $promo = Promotion::where('status', 'active')
            ->where('promo_type', 'discount')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->where(function ($q) use ($roomType) {
                $q->whereNull('room_type_id')
                  ->orWhere('room_type_id', $roomType->id);
            })
            ->orderByDesc('discount_value')
            ->first();

        $discount = 0;
        if ($promo) {
            $discount = $promo->discount_type === 'percentage'
                ? round(($roomCharge * (float) $promo->discount_value) / 100, 2)
                : (float) $promo->discount_value;
        }

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
     */
    public function ensureBooking(Reservation $reservation, string $bookingStatus = 'pending'): Booking
    {
        if ($reservation->booking) {
            return $reservation->booking;
        }

        return Booking::create([
            'reservation_id' => $reservation->id,
            'booking_date' => now(),
            'booking_status' => $bookingStatus,
        ]);
    }

    /**
     * Ensure a Billing row exists, seeded with the room charge/discount
     * locked in at the time it's first created. Deliberately does NOT
     * overwrite room_charge/discount on an existing Billing (see
     * applyStayCharges for what does get refreshed) - a guest who
     * pre-paid keeps the rate/discount they were quoted even if a
     * promotion expires before checkout.
     */
    public function ensureBilling(Booking $booking, Reservation $reservation): Billing
    {
        if ($booking->billing) {
            return $booking->billing;
        }

        $quote = $this->quoteRoomCharge($reservation);

        return Billing::create([
            'booking_id' => $booking->id,
            'room_charge' => $quote['room_charge'],
            'discount' => $quote['discount'],
            'total_amount' => $quote['total'],
            'billing_status' => 'pending',
        ]);
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
            $booking = $this->ensureBooking($reservation, $staffRecorded ? 'confirmed' : 'pending');
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
                if ($booking->booking_status !== 'confirmed') {
                    $booking->update(['booking_status' => 'confirmed']);
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
            if ($booking && $booking->booking_status !== 'confirmed') {
                $booking->update(['booking_status' => 'confirmed']);
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
