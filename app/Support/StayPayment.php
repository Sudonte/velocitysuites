<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Payment;

/**
 * Where a stay stands on payment - the one answer every staff and guest screen
 * shows (Check In panel, Expected Check-ins, the booking page, Check-out, the
 * guest's own pages): unpaid, partly paid, fully paid, or OVERPAID - e.g. the
 * guest paid in full when booking and a discount was approved afterwards, or
 * checked out early. An overpaid stay always tells the receptionist how much
 * to hand back; once they confirm it, the change is recorded as a negative
 * payment, so what the stay is recorded as paid equals its actual total.
 */
final class StayPayment
{
    /** @return array{total: float, paid: float, balance: float, excess: float, state: string, label: string, badge: string} */
    public static function of(Booking $booking): array
    {
        $booking->unsetRelation('billing');

        return self::from($booking->payableTotal(), $booking->paidTotal());
    }

    /** @return array{total: float, paid: float, balance: float, excess: float, state: string, label: string, badge: string} */
    public static function from(float $total, float $paid): array
    {
        $total = round($total, 2);
        $paid = round($paid, 2);
        $balance = round(max(0, $total - $paid), 2);
        $excess = round(max(0, $paid - $total), 2);

        [$state, $label, $badge] = match (true) {
            $excess > 0.004 => ['overpaid', 'Overpaid - return ₱' . number_format($excess, 2), 'bg-primary'],
            $balance <= 0.004 && $total > 0 => ['paid', 'Fully paid', 'bg-success'],
            $paid > 0.004 => ['partial', 'Partially paid', 'bg-warning text-dark'],
            default => ['unpaid', 'Unpaid', 'bg-secondary'],
        };

        return compact('total', 'paid', 'balance', 'excess', 'state', 'label', 'badge');
    }

    /** One-line note for a success message after something lowered the total (a discount approved, an early check-out). */
    public static function returnNote(Booking $booking): string
    {
        $excess = self::of($booking)['excess'];

        return $excess > 0.004 ? ' The guest already paid more than the new total - return ₱' . number_format($excess, 2) . ' to them.' : '';
    }

    /**
     * Record the excess as handed back to the guest: a negative completed
     * payment, so the stay's paid total now equals its actual total.
     */
    public static function recordChangeReturned(Booking $booking, float $amount, string $where, ?int $billingId = null): Payment
    {
        $payment = DeskPayment::record($booking, 'cash', -round($amount, 2), array_filter([
            'reference_number' => 'CHANGE-RETURNED-' . $booking->id . '-' . now()->format('YmdHis'),
            'billing_id' => $billingId,
        ]));
        Activity::log('Returned change to guest', "Booking #{$booking->id} - ₱" . number_format($amount, 2) . " handed back ({$where})", $booking);

        return $payment;
    }
}
