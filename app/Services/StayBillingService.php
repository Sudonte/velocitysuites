<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Booking;
use App\Support\StayBill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * One place that creates and refreshes a booking's Billing - used at check-in
 * (the bill is created and settled there) and at check-out (the bill is
 * re-priced for the nights actually stayed and anything added since).
 *
 * Pricing always comes from StayBill: PROJECTED (scheduled nights) at
 * check-in, FINAL (nights actually stayed) at check-out.
 */
class StayBillingService
{
    /** The booking's Billing, created if it doesn't exist yet (one per booking - DB-unique). */
    public function ensure(Booking $booking, string $mode = StayBill::FINAL): Billing
    {
        if ($existing = $booking->billing()->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($booking, $mode) {
                $billing = Billing::create([
                    'booking_id' => $booking->id,
                    'room_charge' => 0,
                    'additional_guest_fee' => 0,
                    'amenity_charge' => 0,
                    'discount' => 0,
                    'total_amount' => 0,
                    'billing_status' => 'pending',
                ]);
                $this->refresh($booking, $billing, $mode);

                return $billing;
            });
        } catch (QueryException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }

            return $booking->billing()->firstOrFail(); // another request just created it
        }
    }

    /**
     * Re-price the bill (rooms, amenities, discount) from StayBill, attach the
     * stay's completed payments to it, and set its status.
     */
    public function refresh(Booking $booking, Billing $billing, string $mode = StayBill::FINAL): void
    {
        $billing->update([
            // Over-capacity stays are blocked (App\Support\GuestCapacity), so there is no extra-guest fee.
            'additional_guest_fee' => 0,
            'amenity_charge' => round($booking->billedAmenityTotal(), 2),
        ]);
        $billing->syncFromStayBill($booking, $mode);

        // Every completed payment on the stay (deposits, check-in payment, change returned) counts toward this bill.
        $payments = $booking->reservation_id ? $booking->reservation->payments() : $booking->payments();
        $payments->countedAsPaid()->whereNull('billing_id')->update(['billing_id' => $billing->id]);

        $paid = (float) $billing->payments()->countedAsPaid()->sum('amount_paid');
        $billing->update([
            'billing_status' => $paid <= 0 ? 'pending' : ($paid + 0.004 >= (float) $billing->total_amount ? 'paid' : 'partial'),
        ]);
    }
}
