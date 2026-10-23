<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRoomLine;
use App\Models\Reservation;
use App\Models\ReservationRoomLine;
use App\Models\Stay;
use App\Support\StaysInput;
use Illuminate\Support\Collection;

/**
 * Writes the stays of a new booking or reservation: one Stay row per stay and, for each, its own room lines (rate x ITS booked nights).
 * The transaction's own check_in / check_out are the overall span (earliest check-in, latest check-out) and room_type_id /
 * rooms_requested stay the first line's type and the total number of rooms, because older code and older apps still read them.
 */
class StayRecorder
{
    /** The columns to put on the booking / reservation row itself. @return array{check_in: mixed, check_out: mixed, room_type_id: int, rooms_requested: int} */
    public function summaryColumns(array $stays): array
    {
        return [
            'check_in' => collect($stays)->map(fn ($s) => $s['check_in'])->min(),
            'check_out' => collect($stays)->map(fn ($s) => $s['check_out'])->max(),
            'room_type_id' => $stays[0]['lines'][0]['room_type']->id,
            'rooms_requested' => (int) collect($stays)->sum(fn ($s) => collect($s['lines'])->sum('quantity')),
        ];
    }

    /**
     * Creates the Stay rows and room lines. Returns the saved stays in the same order as $stays so the caller can attach amenities.
     *
     * @return Collection<int, Stay>
     */
    public function record(Booking|Reservation $transaction, array $stays): Collection
    {
        $saved = collect();
        foreach (array_values($stays) as $i => $stay) {
            // the first stay already exists (every transaction gets one when it is created): give it this stay's own dates
            $row = $i === 0 ? $transaction->stays()->orderBy('position')->orderBy('id')->first() : null;
            $attrs = ['position' => $i + 1, 'check_in' => $stay['check_in'], 'check_out' => $stay['check_out']];
            if ($row) {
                $row->update($attrs);
            } else {
                $row = $transaction->stays()->create($attrs);
            }
            $nights = StaysInput::nights($stay);
            foreach ($stay['lines'] as $line) {
                $roomType = $line['room_type'];
                $lineAttrs = [
                    'stay_id' => $row->id,
                    'room_type_id' => $roomType->id,
                    'room_type_name' => $roomType->name,
                    'quantity' => $line['quantity'],
                    'price_per_night' => $roomType->rate,
                    'number_of_nights' => $nights,
                    'subtotal' => round((float) $roomType->rate * $nights * $line['quantity'], 2),
                ];
                $transaction instanceof Booking
                    ? BookingRoomLine::create($lineAttrs + ['booking_id' => $transaction->id])
                    : ReservationRoomLine::create($lineAttrs + ['reservation_id' => $transaction->id]);
            }
            $saved->push($row);
        }
        $transaction->unsetRelation('stays');

        return $saved;
    }

    /**
     * Copies a reservation's stays (dates, room lines, amenity snapshot rows) onto the booking it was converted into, so the booking
     * is a complete transaction of its own: same stays, same prices.
     */
    public function copyReservationToBooking(Reservation $reservation, Booking $booking): void
    {
        $map = [];
        $existing = $booking->stays()->orderBy('position')->orderBy('id')->get(); // the stay creation made from the booking's own dates
        foreach ($reservation->stayList() as $i => $stay) {
            $attrs = ['position' => $stay->position, 'check_in' => $stay->check_in, 'check_out' => $stay->check_out];
            $copy = $existing->get($i);
            if ($copy) {
                $copy->update($attrs);
            } else {
                $copy = $booking->stays()->create($attrs);
            }
            $map[$stay->id ?? 0] = $copy->id;
        }
        $firstId = $booking->stays()->orderBy('position')->value('id');
        // the reservation's amenity rows are the booking's from now on: point them at the booking's own stays
        foreach ($map as $oldId => $newId) {
            \App\Models\AmenityRequest::where('reservation_id', $reservation->id)
                ->where(fn ($q) => $oldId ? $q->where('stay_id', $oldId) : $q->whereNull('stay_id'))
                ->update(['stay_id' => $newId]);
        }
        \App\Models\AmenityRequest::where('reservation_id', $reservation->id)->whereNull('stay_id')->update(['stay_id' => $firstId]);

        foreach ($reservation->roomLines()->get() as $line) {
            $booking->roomLines()->create([
                'stay_id' => $map[$line->stay_id ?? 0] ?? $firstId,
                'room_type_id' => $line->room_type_id,
                'room_type_name' => $line->room_type_name,
                'quantity' => $line->quantity,
                'price_per_night' => $line->price_per_night,
                'number_of_nights' => $line->number_of_nights,
                'subtotal' => $line->subtotal,
            ]);
        }
        $booking->unsetRelation('stays');
        $booking->unsetRelation('roomLines');
    }
}
