<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the guest-facing Transaction Timeline for a Reservation or Booking
 * from what the system actually recorded (created_at, edited_at, payment
 * verified_at, discount_verified_at, checked_in_at, checked_out_at,
 * completed_at) - one ordered list the mobile app renders as-is.
 *
 * Each step is ['key', 'label', 'status', 'at'] where `at` is an ISO-8601 UTC
 * timestamp (null only for a step that has not happened yet) and `status` is
 * one of: Verified (a receptionist verified it), Pending (waiting on staff),
 * Rejected, Recorded (an event with nothing to verify, e.g. "created").
 *
 * A COMPLETED transaction is closed out by the receptionist, so every step in
 * it is returned as Verified with a timestamp - never Pending, blank or N/A.
 */
class TransactionTimeline
{
    public const VERIFIED = 'Verified';

    public const PENDING = 'Pending';

    public const REJECTED = 'Rejected';

    public const RECORDED = 'Recorded';

    public static function forReservation(Reservation $reservation): array
    {
        $booking = $reservation->booking;

        return self::build($reservation, $booking);
    }

    public static function forBooking(Booking $booking): array
    {
        return self::build($booking->reservation, $booking);
    }

    private static function build(?Reservation $reservation, ?Booking $booking): array
    {
        $isDirect = $booking !== null && $booking->reservation_id === null;
        $origin = $isDirect ? $booking : ($reservation ?? $booking);
        $steps = [];

        $steps[] = self::step(
            'created',
            $isDirect ? 'Booking created' : 'Reservation created',
            self::RECORDED,
            $origin->created_at
        );

        if ($reservation && $reservation->edited_at) {
            $steps[] = self::step('reservation_modified', 'Reservation modified by guest', self::RECORDED, $reservation->edited_at);
        }

        // Payments, oldest first: one step each, Verified once a receptionist verified it.
        $payments = self::payments($reservation, $booking);
        foreach ($payments as $payment) {
            // 'completed' is the status a payment only reaches once staff accepted it (verify(),
            // or recorded at checkout), so it counts as verified even where verified_at was
            // never stamped (checkout-collected payments).
            if ($payment->verified_at || $payment->payment_status === 'completed') {
                $steps[] = self::step('payment_'.$payment->id, 'Payment verified', self::VERIFIED, $payment->verified_at ?? $payment->payment_date ?? $payment->created_at);
            } elseif ($payment->rejected_at || $payment->payment_status === 'rejected') {
                $steps[] = self::step('payment_'.$payment->id, 'Payment rejected', self::REJECTED, $payment->rejected_at ?? $payment->updated_at);
            } else {
                $steps[] = self::step('payment_'.$payment->id, 'Payment awaiting verification', self::PENDING, $payment->payment_date ?? $payment->created_at);
            }
        }

        // Discount ID verification, only when the guest claimed a discount.
        $discountSource = $reservation ?? $booking;
        if ($discountSource && $discountSource->discount_requested) {
            $verified = ($booking?->discount_verification_status === 'approved') || ($reservation?->discount_verification_status === 'approved');
            $rejected = ($booking?->discount_verification_status === 'rejected') || ($reservation?->discount_verification_status === 'rejected');
            $at = $booking?->discount_verified_at ?? $booking?->billing?->discount_verified_at;
            if ($verified) {
                $steps[] = self::step('discount_verified', 'Discount ID verified', self::VERIFIED, $at ?? $booking?->updated_at ?? $reservation?->updated_at);
            } elseif ($rejected) {
                $steps[] = self::step('discount_verified', 'Discount ID rejected', self::REJECTED, $at ?? $discountSource->updated_at);
            } else {
                $steps[] = self::step('discount_verified', 'Discount ID verification', self::PENDING, null);
            }
        }

        if ($booking && ! $isDirect) {
            $steps[] = self::step('confirmed', 'Reservation confirmed', self::VERIFIED, $booking->verified_at ?? $booking->confirmed_at ?? $booking->created_at);
        } elseif ($booking && $isDirect) {
            $steps[] = $booking->verified_at
                ? self::step('confirmed', 'Booking confirmed', self::VERIFIED, $booking->verified_at)
                : self::step('confirmed', 'Booking confirmation', self::PENDING, null);
        } elseif ($reservation && ! in_array($reservation->status, [Reservation::STATUS_CANCELLED, Reservation::STATUS_REJECTED], true)) {
            $steps[] = self::step('confirmed', 'Reservation confirmation', self::PENDING, null);
        }

        if ($booking) {
            if ($booking->checked_in_at) {
                $steps[] = self::step('checked_in', 'Checked in', self::VERIFIED, $booking->checked_in_at);
            }
            if ($booking->checked_out_at) {
                $steps[] = self::step('checked_out', 'Checked out', self::VERIFIED, $booking->checked_out_at);
            }
            if ($booking->completed_at) {
                $steps[] = self::step('completed', 'Completed', self::VERIFIED, $booking->completed_at);
            }
            if ($booking->booking_status === Booking::STATUS_CANCELLED) {
                $steps[] = self::step('cancelled', 'Booking cancelled', self::RECORDED, $booking->cancelled_at ?? $booking->updated_at);
            }
        } elseif ($reservation) {
            if ($reservation->status === Reservation::STATUS_CANCELLED) {
                $steps[] = self::step('cancelled', 'Reservation cancelled', self::RECORDED, $reservation->cancelled_at ?? $reservation->updated_at);
            } elseif ($reservation->status === Reservation::STATUS_REJECTED) {
                $steps[] = self::step('rejected', 'Reservation rejected', self::REJECTED, $reservation->cancelled_at ?? $reservation->updated_at);
            }
        }

        if ($booking && $booking->booking_status === Booking::STATUS_COMPLETED) {
            $steps = self::closeOut($steps, $booking);
        }

        return $steps;
    }

    /**
     * A completed transaction: every step Verified and dated. A step that has no
     * timestamp of its own (older data, or a non-verifiable step) inherits the
     * previous step's, falling back to the booking's last update.
     */
    private static function closeOut(array $steps, Booking $booking): array
    {
        $previous = $booking->created_at ? Carbon::parse($booking->created_at)->utc()->toIso8601String() : null;
        foreach ($steps as &$step) {
            $step['status'] = self::VERIFIED;
            if (empty($step['at'])) {
                $step['at'] = $previous ?? Carbon::parse($booking->updated_at)->utc()->toIso8601String();
            }
            $previous = $step['at'];
        }
        unset($step);

        return $steps;
    }

    /** @return Collection<int, Payment> */
    private static function payments(?Reservation $reservation, ?Booking $booking): Collection
    {
        $payments = $booking
            ? $booking->allPayments()
            : ($reservation ? $reservation->payments : collect());

        // A finished stay's timeline shows the payments that actually settled it, not the
        // superseded attempts (rejected / failed / cancelled) that came before.
        if ($booking && $booking->booking_status === Booking::STATUS_COMPLETED) {
            $payments = $payments->where('payment_status', 'completed');
        }

        return $payments->sortBy('created_at')->values();
    }

    private static function step(string $key, string $label, string $status, $at): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'at' => $at ? Carbon::parse($at)->utc()->toIso8601String() : null,
        ];
    }
}
