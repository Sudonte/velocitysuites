<?php

namespace App\Models\Concerns;

use App\Models\Stay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking and Reservation are ONE transaction that holds one or more stays. This is the one place that knows how to read them.
 *
 * stayList() always has at least one entry: a transaction without stay rows (older code, a fixture) gets one VIRTUAL stay built from
 * its own check_in / check_out, so no reader needs a special case for it. ensureStays() saves that stay (and points the transaction's
 * children at it) at the moment something must be recorded per stay - checking a stay in or out.
 */
trait HasStays
{
    /** The raw stay rows never leak into JSON under `stays`: that key carries the stays payload (withStays()). */
    public function initializeHasStays(): void
    {
        $this->hidden[] = 'stays';
    }

    /** A transaction always has at least one stay row: created from its own dates the moment it exists (a multi-stay create then re-uses it). */
    public static function bootHasStays(): void
    {
        static::created(function ($transaction) {
            // a record with no dates (a bare fixture) has nothing to make a stay from; new rows have no children to adopt yet
            if ($transaction->check_in !== null && $transaction->check_out !== null) {
                $transaction->ensureStays(adoptChildren: false);
            }
        });
    }

    /** Eager-loaded or lazy: the saved stays in order. */
    public function stays()
    {
        return $this->hasMany(Stay::class)->orderBy('position')->orderBy('id');
    }

    /** @return Collection<int, Stay> */
    public function stayList(): Collection
    {
        $saved = $this->relationLoaded('stays') ? $this->getRelation('stays') : $this->stays()->get();
        if ($saved->isNotEmpty()) {
            $this->setRelation('stays', $saved); // read once per request

            return $saved->values();
        }

        $virtual = new Stay([
            $this->getForeignKey() => $this->getKey(),
            'position' => 1,
            'check_in' => $this->check_in,
            'check_out' => $this->check_out,
            'checked_in_at' => $this instanceof \App\Models\Booking ? $this->checked_in_at : null,
            'checked_out_at' => $this instanceof \App\Models\Booking ? $this->checked_out_at : null,
        ]);

        return collect([$virtual]);
    }

    public function stayCount(): int
    {
        return $this->stayList()->count();
    }

    public function hasSeveralStays(): bool
    {
        return $this->stayCount() > 1;
    }

    /** The stay a child row belongs to: its own stay_id, or - for a row written before stays existed - the first stay. */
    public function stayIdFor(?int $stayId): ?int
    {
        return $stayId ?? $this->stayList()->first()?->id;
    }

    /** Saves the single stay of a transaction that has none yet, and points its room lines / rooms / amenities at it. */
    public function ensureStays(bool $adoptChildren = true): Collection
    {
        if ($this->stays()->exists()) {
            $this->unsetRelation('stays');

            return $this->stayList();
        }

        DB::transaction(function () use ($adoptChildren) {
            $stay = $this->stays()->create([
                'position' => 1,
                'check_in' => $this->check_in,
                'check_out' => $this->check_out,
                'checked_in_at' => $this instanceof \App\Models\Booking ? $this->checked_in_at : null,
                'checked_out_at' => $this instanceof \App\Models\Booking ? $this->checked_out_at : null,
            ]);
            if (! $adoptChildren) {
                return;
            }
            $key = $this->getForeignKey();
            $children = $this instanceof \App\Models\Booking
                ? ['booking_room_lines', 'booking_rooms']
                : ['reservation_room_lines', 'reservation_amenities'];
            $children[] = 'amenity_requests';
            foreach ($children as $table) {
                if (Schema::hasColumn($table, 'stay_id')) {
                    DB::table($table)->where($key, $this->getKey())->whereNull('stay_id')->update(['stay_id' => $stay->id]);
                }
            }
        });
        $this->unsetRelation('stays');

        return $this->stayList();
    }

    /** When true, toArray() carries the `stays` payload (the guest API opts in; internal uses of toArray() stay as cheap as before). */
    public bool $withStaysInArray = false;

    public function withStays(): static
    {
        $this->withStaysInArray = true;

        return $this;
    }

    public function toArray()
    {
        $array = parent::toArray();
        if ($this->withStaysInArray) {
            $array['stays'] = $this->staysPayload();
        }

        return $array;
    }

    /** upcoming | in_house | checked_out for one stay: its own moments, else (older single-stay rows) the booking's status. */
    public function stateOf(Stay $stay): string
    {
        if (! $this instanceof \App\Models\Booking) {
            return Stay::UPCOMING;
        }
        if ($this->booking_status === \App\Models\Booking::STATUS_COMPLETED) {
            return Stay::CHECKED_OUT;
        }
        $own = $stay->state();
        if ($own !== Stay::UPCOMING) {
            return $own;
        }

        return $this->stayCount() === 1 && $this->booking_status === \App\Models\Booking::STATUS_CHECKED_IN ? Stay::IN_HOUSE : Stay::UPCOMING;
    }

    /**
     * The stays as the API and the screens show them: dates, nights, state, the rooms booked (with the physical rooms once assigned),
     * the amenities and the stay's own subtotal (rooms for ITS booked nights + its amenities).
     *
     * @return array<int,array<string,mixed>>
     */
    public function staysPayload(): array
    {
        $isBooking = $this instanceof \App\Models\Booking;
        $stays = $this->stayList();
        $firstId = $stays->first()?->id;
        $lines = $this->relationLoaded('roomLines') ? $this->getRelation('roomLines') : $this->roomLines()->get();
        $assigned = $isBooking ? $this->rooms : collect();
        $amenityRows = $isBooking
            ? ($this->preloadedAmenityRequests ?? $this->billableAmenityRequests()->get())
            : $this->bookingAmenities;

        return $stays->values()->map(function (Stay $stay, int $i) use ($lines, $assigned, $amenityRows, $firstId, $isBooking, $stays) {
            $mine = fn ($row) => ($row->stay_id ?? $firstId) === $stay->id;
            $stayLines = $lines->filter($mine)->values();
            $stayRooms = $assigned->filter(fn ($room) => (($room->pivot->stay_id ?? $firstId) === $stay->id))->values();
            $nights = $stay->nights();

            if ($stayLines->isEmpty() && $this->room_type_id && $stays->count() === 1) {
                $rate = (float) ($this->roomType->rate ?? 0);
                $rooms = [[
                    'room_type_id' => (string) $this->room_type_id,
                    'room_type' => $this->roomType->name ?? null,
                    'quantity' => max(1, (int) $this->rooms_requested),
                    'price_per_night' => $rate,
                    'nights' => $nights,
                    'subtotal' => round($rate * $nights * max(1, (int) $this->rooms_requested), 2),
                    'assigned_room_numbers' => $stayRooms->pluck('room_number')->values()->all(),
                ]];
            } else {
                $byType = $stayRooms->groupBy('room_type_id');
                $rooms = $stayLines->map(fn ($line) => [
                    'room_type_id' => (string) $line->room_type_id,
                    'room_type' => $line->room_type_name,
                    'quantity' => (int) $line->quantity,
                    'price_per_night' => (float) $line->price_per_night,
                    'nights' => (int) $line->number_of_nights,
                    'subtotal' => (float) $line->subtotal,
                    'assigned_room_numbers' => ($byType->get($line->room_type_id) ?? collect())->pluck('room_number')->values()->all(),
                ])->all();
            }

            $amenities = $amenityRows->filter($mine)->map(function ($a) use ($isBooking) {
                $unit = (float) $a->charge;
                $qty = (int) $a->quantity;

                return [
                    'amenity_id' => (string) $a->amenity_id,
                    'amenity_name' => $a->amenity_name,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'subtotal' => round($unit * $qty, 2),
                    'status' => $isBooking ? $a->status : null,
                ] + \App\Support\RoomLineRef::payload($a);
            })->values()->all();

            $roomsSubtotal = round((float) collect($rooms)->sum('subtotal'), 2);
            $amenitiesSubtotal = round((float) collect($amenities)->sum('subtotal'), 2);

            return [
                'id' => $stay->id,
                'position' => (int) ($stay->position ?? $i + 1),
                'check_in' => $stay->check_in->format('Y-m-d'),
                'check_out' => $stay->check_out->format('Y-m-d'),
                'nights' => $nights,
                'state' => $this->stateOf($stay),
                'checked_in_at' => $stay->checked_in_at?->toIso8601String(),
                'checked_out_at' => $stay->checked_out_at?->toIso8601String(),
                'rooms' => $rooms,
                'assigned_rooms' => $stayRooms->map(fn ($room) => [
                    'room_id' => $room->id,
                    'room_number' => $room->room_number,
                    'room_type' => $room->roomType?->name,
                    'checked_out' => (bool) ($room->pivot->checked_out_at ?? false),
                ])->values()->all(),
                'amenities' => $amenities,
                'rooms_subtotal' => $roomsSubtotal,
                'amenities_subtotal' => $amenitiesSubtotal,
                'subtotal' => round($roomsSubtotal + $amenitiesSubtotal, 2),
            ];
        })->all();
    }

    /** check_in / check_out of the transaction = the earliest check-in and the latest check-out of its stays (older code reads these). */
    public function syncSpanFromStays(): void
    {
        $stays = $this->stayList();
        $this->forceFill([
            'check_in' => $stays->min(fn (Stay $s) => $s->check_in),
            'check_out' => $stays->max(fn (Stay $s) => $s->check_out),
        ])->save();
    }
}
