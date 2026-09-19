<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Best-effort grouping of Bookings/Reservations created together in one
 * multi-room-type guest transaction, for the receptionist web UI only.
 *
 * There is no authoritative server-side link between sibling rows for
 * organic guest data: Api\ReservationController::store() and
 * DirectBookingService::create() each accept exactly one room type per
 * call, so a guest selecting e.g. Deluxe x1 + Suite x1 in one checkout
 * submits two independent, unlinked Reservation/Booking rows - the
 * Android app hides this by grouping sibling ids client-side only
 * (BookingGroupState, on-device SharedPreferences - "no server-side
 * equivalent to sync this to" per its own doc), which the receptionist
 * backend has no access to. `room_lines` (BookingRoomLine/
 * ReservationRoomLine) IS a real, authoritative single-row multi-room-type
 * representation, but as of 2026-09-19 is only populated for a small set
 * of historical/backfilled records, not live guest submissions - see
 * MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md.
 *
 * This service infers likely siblings by matching the same guest + same
 * check-in/check-out + created within a short window of each other - the
 * same signal a human receptionist would use ("these came in together").
 * Display-only: never used for anything that mutates data, and every
 * caller must prefer real room_lines when present over this heuristic.
 */
class TransactionGroupingService
{
    /** How close together two rows must have been created to be considered part of the same checkout. */
    private const WINDOW_MINUTES = 5;

    /**
     * Every Booking created in the same likely transaction as $booking
     * (itself included), ordered by id - null if $booking has no
     * resolvable guest to group by, or no siblings were found.
     */
    public function siblingsForBooking(Booking $booking): ?Collection
    {
        $guestId = $booking->account_guest?->id;
        if (! $guestId || ! $booking->check_in || ! $booking->check_out || ! $booking->created_at) {
            return null;
        }

        $siblings = Booking::query()
            ->where(function ($q) use ($guestId) {
                $q->where('guest_id', $guestId)
                    ->orWhereHas('reservation', fn ($r) => $r->where('guest_id', $guestId));
            })
            ->whereDate('check_in', $booking->check_in->toDateString())
            ->whereDate('check_out', $booking->check_out->toDateString())
            ->whereBetween('created_at', [
                $booking->created_at->copy()->subMinutes(self::WINDOW_MINUTES),
                $booking->created_at->copy()->addMinutes(self::WINDOW_MINUTES),
            ])
            ->with(['roomType', 'rooms', 'reservation'])
            ->orderBy('id')
            ->get();

        return $siblings->count() > 1 ? $siblings : null;
    }

    /** Same idea as siblingsForBooking(), for a still-unconverted Reservation. */
    public function siblingsForReservation(Reservation $reservation): ?Collection
    {
        if (! $reservation->guest_id || ! $reservation->check_in || ! $reservation->check_out || ! $reservation->created_at) {
            return null;
        }

        $siblings = Reservation::query()
            ->where('guest_id', $reservation->guest_id)
            ->whereDate('check_in', $reservation->check_in->toDateString())
            ->whereDate('check_out', $reservation->check_out->toDateString())
            ->whereBetween('created_at', [
                $reservation->created_at->copy()->subMinutes(self::WINDOW_MINUTES),
                $reservation->created_at->copy()->addMinutes(self::WINDOW_MINUTES),
            ])
            ->with('roomType')
            ->orderBy('id')
            ->get();

        return $siblings->count() > 1 ? $siblings : null;
    }

    /**
     * Unified per-room-type line items for the receptionist Booking Details
     * view - tiered fallback, most-authoritative first: (1) real room_lines
     * when this exact Booking has them (a genuine single-row multi-room-type
     * transaction), (2) one line per heuristic sibling when siblings() found
     * any, (3) a single line built from this Booking's own legacy
     * room_type_id/rooms_requested. Every tier returns the same shape, so
     * the view never needs to know which one it got.
     */
    public function roomLinesForBooking(Booking $booking, ?Collection $siblings): array
    {
        if (! empty($booking->room_lines)) {
            return $booking->room_lines;
        }

        if ($siblings) {
            return $siblings->map(function (Booking $b) {
                $nights = $b->number_of_nights;
                $rate = (float) ($b->roomType->rate ?? 0);

                return [
                    'room_type_id' => (string) $b->room_type_id,
                    'room_type' => $b->roomType->name ?? 'N/A',
                    'quantity' => $b->rooms_requested,
                    'price_per_night' => $rate,
                    'nights' => $nights,
                    'subtotal' => round($rate * $nights * $b->rooms_requested, 2),
                    'assigned_room_numbers' => $b->rooms->pluck('room_number')->values()->all(),
                ];
            })->values()->all();
        }

        $nights = $booking->number_of_nights;
        $rate = (float) ($booking->roomType->rate ?? 0);

        return [[
            'room_type_id' => (string) $booking->room_type_id,
            'room_type' => $booking->roomType->name ?? 'N/A',
            'quantity' => $booking->rooms_requested,
            'price_per_night' => $rate,
            'nights' => $nights,
            'subtotal' => round($rate * $nights * $booking->rooms_requested, 2),
            'assigned_room_numbers' => $booking->rooms->pluck('room_number')->values()->all(),
        ]];
    }

    /** Same idea as roomLinesForBooking(), for a still-unconverted Reservation - never has assigned_room_numbers (only set at check-in, against the converted Booking). */
    public function roomLinesForReservation(Reservation $reservation, ?Collection $siblings): array
    {
        if (! empty($reservation->room_lines)) {
            return $reservation->room_lines;
        }

        if ($siblings) {
            return $siblings->map(function (Reservation $r) {
                $nights = $r->number_of_nights;
                $rate = (float) ($r->roomType->rate ?? 0);

                return [
                    'room_type_id' => (string) $r->room_type_id,
                    'room_type' => $r->roomType->name ?? 'N/A',
                    'quantity' => $r->rooms_requested,
                    'price_per_night' => $rate,
                    'nights' => $nights,
                    'subtotal' => round($rate * $nights * $r->rooms_requested, 2),
                    'assigned_room_numbers' => [],
                ];
            })->values()->all();
        }

        $nights = $reservation->number_of_nights;
        $rate = (float) ($reservation->roomType->rate ?? 0);

        return [[
            'room_type_id' => (string) $reservation->room_type_id,
            'room_type' => $reservation->roomType->name ?? 'N/A',
            'quantity' => $reservation->rooms_requested,
            'price_per_night' => $rate,
            'nights' => $nights,
            'subtotal' => round($rate * $nights * $reservation->rooms_requested, 2),
            'assigned_room_numbers' => [],
        ]];
    }
}
