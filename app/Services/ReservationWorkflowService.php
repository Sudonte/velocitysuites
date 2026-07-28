<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes the Reservation status-transition rules so they aren't
 * duplicated between the guest-facing controller (initial status + later
 * deposit payments) and the receptionist-facing controller (accept/reject/
 * convert) - both call into this instead of setting `status` directly.
 */
class ReservationWorkflowService
{
    public function __construct(private RoomAvailabilityService $availability)
    {
    }

    /**
     * Status a brand-new reservation should start at. Cash always behaves
     * like Pay Later - it can't be verified online, so the receptionist
     * reconciles it in person regardless of Pay Now/Later choice. Only
     * Pay Now + GCash (a payment that's already been made and just needs
     * staff to glance at the receipt) skips straight to ready_for_booking.
     */
    public function initialStatus(string $paymentPreference, ?string $paymentMethod): string
    {
        if ($paymentPreference === 'pay_now' && $paymentMethod === 'gcash') {
            return 'ready_for_booking';
        }

        return 'pending_review';
    }

    /**
     * Guest submits a GCash deposit - either at booking time (pay_now) or
     * later against an existing Pay Later reservation. The payment starts
     * 'pending' (unverified); landing here auto-advances a pending_review
     * reservation to ready_for_booking, since GCash is the only payment
     * method that can be self-reported by the guest and still trusted
     * enough to skip straight to "ready for staff to convert" - per spec,
     * only an *online* payment triggers this automatic move. Cash can
     * never be verified online, so it's recorded separately (see
     * recordCashIntent()) without advancing the status.
     */
    public function recordDepositPayment(Reservation $reservation, array $paymentData): Payment
    {
        $payment = Payment::create(array_merge($paymentData, [
            'reservation_id' => $reservation->id,
            'billing_id' => null,
            'payment_stage' => 'deposit',
            'payment_status' => 'pending',
            'payment_date' => now(),
        ]));

        if ($reservation->status === 'pending_review') {
            $reservation->update(['status' => 'ready_for_booking']);
        }

        return $payment;
    }

    /**
     * Guest states an intended cash amount at reservation time - purely
     * informational for the receptionist, never auto-advances the status
     * (cash always behaves like Pay Later: stays in "To Be Confirmed"
     * until staff reviews it in person).
     */
    public function recordCashIntent(Reservation $reservation, float $amount): Payment
    {
        return Payment::create([
            'reservation_id' => $reservation->id,
            'billing_id' => null,
            'payment_method' => 'cash',
            'amount_paid' => $amount,
            'payment_stage' => 'deposit',
            'payment_status' => 'pending',
            'payment_date' => now(),
        ]);
    }

    /**
     * Receptionist accepts a Pay Later / Cash reservation that's still
     * awaiting review - moves it to "To Be Converted to Booking".
     */
    public function accept(Reservation $reservation): void
    {
        abort_unless($reservation->status === 'pending_review', 422, 'Only a reservation awaiting review can be accepted.');

        $reservation->update(['status' => 'ready_for_booking']);
    }

    /**
     * Receptionist rejects a reservation from either tab. Always requires a
     * reason (e.g. ineligible, or the room type is fully booked for the
     * requested dates).
     */
    public function reject(Reservation $reservation, string $reason, User $staff): void
    {
        abort_unless(
            in_array($reservation->status, ['pending_review', 'ready_for_booking']),
            422,
            'Only an active reservation can be rejected.'
        );

        DB::transaction(function () use ($reservation, $reason, $staff) {
            $reservation->update(['status' => 'rejected', 'rejection_reason' => $reason]);

            $reservation->payments()
                ->where('payment_stage', 'deposit')
                ->where('payment_status', 'pending')
                ->get()
                ->each(fn ($payment) => $payment->update([
                    'payment_status' => 'failed',
                    'verified_by' => $staff->id,
                    'verified_at' => now(),
                ]));
        });
    }

    /**
     * Convert a ready_for_booking reservation into a Booking - gated on
     * room-type inventory actually being available for the requested
     * dates. Verifying the deposit (if any) happens implicitly here, since
     * converting IS the verification act.
     */
    public function convertToBooking(Reservation $reservation, User $staff): Booking
    {
        abort_unless($reservation->status === 'ready_for_booking', 422, 'Only a reservation ready for booking can be converted.');

        if ($this->availability->isFullyBooked($reservation->roomType, $reservation->check_in, $reservation->check_out)) {
            abort(422, 'This room type is fully booked for the requested dates.');
        }

        return DB::transaction(function () use ($reservation, $staff) {
            $booking = Booking::create([
                'reservation_id' => $reservation->id,
                'room_type_id' => $reservation->room_type_id,
                'check_in' => $reservation->check_in,
                'check_out' => $reservation->check_out,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'number_of_guests' => $reservation->number_of_guests,
                'confirmed_at' => now(),
                'booking_status' => 'confirmed',
            ]);

            $reservation->update(['status' => 'converted']);

            $reservation->payments()
                ->where('payment_stage', 'deposit')
                ->where('payment_status', 'pending')
                ->get()
                ->each(fn ($payment) => $payment->update([
                    'payment_status' => 'completed',
                    'verified_by' => $staff->id,
                    'verified_at' => now(),
                ]));

            return $booking;
        });
    }
}
