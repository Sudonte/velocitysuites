<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TransactionDeletionArchive;
use Illuminate\Support\Facades\DB;

/**
 * Shared by Api\BookingController::destroy() (direct bookings) and
 * Api\ReservationController::destroy() (reservations, converted or not) -
 * archives every payment/billing record belonging to a transaction the
 * guest is permanently deleting, then removes the live rows that would
 * otherwise block deleting the booking/reservation itself (payments.
 * booking_id/reservation_id and amenity_requests.booking_id are all ON
 * DELETE RESTRICT). Must be called from inside the caller's own
 * DB::transaction() closure, before the booking/reservation rows
 * themselves are deleted - see TransactionDeletionArchive's own docblock
 * for the full rationale.
 */
class TransactionArchiveService
{
    /**
     * @param Reservation|null $reservation Pass null for a genuinely direct Booking.
     * @param Booking|null $booking Pass null for a Reservation that never converted.
     */
    public function archiveAndPurgeFinancials(?Reservation $reservation, ?Booking $booking, ?int $guestId): void
    {
        $billing = $booking?->billing;

        $payments = Payment::query()
            ->when($reservation, fn ($q) => $q->orWhere('reservation_id', $reservation->id))
            ->when($booking, fn ($q) => $q->orWhere('booking_id', $booking->id))
            ->when($billing, fn ($q) => $q->orWhere('billing_id', $billing->id))
            ->get();

        $displayReference = $booking ? "BOOKING-{$booking->id}" : "RESERVATION-{$reservation->id}";

        $snapshot = [
            'reservation' => $reservation?->getAttributes(),
            'booking' => $booking?->getAttributes(),
            'billing' => $billing?->getAttributes(),
            'payments' => $payments->map(fn (Payment $p) => $p->getAttributes())->all(),
            'reservation_room_lines' => $reservation
                ? DB::table('reservation_room_lines')->where('reservation_id', $reservation->id)->get()->all()
                : [],
            'reservation_amenities' => $reservation
                ? DB::table('reservation_amenities')->where('reservation_id', $reservation->id)->get()->all()
                : [],
            'booking_room_lines' => $booking
                ? DB::table('booking_room_lines')->where('booking_id', $booking->id)->get()->all()
                : [],
            'booking_amenity_lines' => $booking
                ? DB::table('booking_amenity_lines')->where('booking_id', $booking->id)->get()->all()
                : [],
            'booking_rooms' => $booking
                ? DB::table('booking_rooms')->where('booking_id', $booking->id)->get()->all()
                : [],
        ];

        TransactionDeletionArchive::create([
            'guest_id' => $guestId,
            'original_reservation_id' => $reservation?->id,
            'original_booking_id' => $booking?->id,
            'display_reference' => $displayReference,
            'transaction_status' => $booking?->booking_status ?? $reservation?->status,
            'total_amount' => $billing?->total_amount ?? $payments->sum('amount_paid'),
            'snapshot' => $snapshot,
            'guest_deleted_at' => now(),
        ]);

        // Now safe to hard-delete the live payment rows (archived above,
        // receipt files left in place in storage - only the DB row goes).
        $paymentIds = $payments->pluck('id');
        if ($paymentIds->isNotEmpty()) {
            Payment::whereIn('id', $paymentIds)->delete();
        }

        // amenity_requests.booking_id is the other RESTRICT blocker;
        // amenity_requests.reservation_id is only SET NULL (not restrict),
        // but clearing both explicitly here avoids leaving a null-reservation_id
        // request row behind for a transaction that no longer exists at all.
        AmenityRequest::query()
            ->when($reservation, fn ($q) => $q->orWhere('reservation_id', $reservation->id))
            ->when($booking, fn ($q) => $q->orWhere('booking_id', $booking->id))
            ->delete();
    }
}
