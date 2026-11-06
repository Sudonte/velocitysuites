<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRoomLine;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Support\CheckInWindow;
use App\Support\HotelClock;
use App\Support\RoomOptions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Extends ONE room of a stay that is past its 12 NN check-out, at the price booked for it. The room only needs a room of its kind to be
 * free on every added night: other bookings may hold the rest of that room type.
 *
 * A stay has one check-out date, so a room leaving on another date than its stay's other rooms becomes a stay of its own (same check-in,
 * new check-out). A room alone in its stay just moves the stay's date. Availability, the bill, the receipts and the guest's app already
 * read each stay's own dates and room lines, so they all follow without knowing about extensions.
 */
class StayExtensionService
{
    public function __construct(
        private RoomAvailabilityService $availability,
        private StayBillingService $billing,
    ) {
    }

    /** @return array{error?:string, stay?:Stay} */
    public function extend(Booking $booking, Room $room, string $newCheckOut): array
    {
        $new = Carbon::parse($newCheckOut)->startOfDay();
        $today = CheckInWindow::date();

        return DB::transaction(function () use ($booking, $room, $new, $today) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->first();
            if (! $locked || $locked->booking_status !== Booking::STATUS_CHECKED_IN) {
                return ['error' => 'Only a checked-in booking can be extended.'];
            }
            $locked->ensureStays();
            $firstId = $locked->stayList()->first()?->id;
            DB::table('booking_rooms')->where('booking_id', $locked->id)->whereNull('stay_id')->update(['stay_id' => $firstId]);
            BookingRoomLine::where('booking_id', $locked->id)->whereNull('stay_id')->update(['stay_id' => $firstId]);

            $pivot = DB::table('booking_rooms')->where('booking_id', $locked->id)->where('room_id', $room->id)->whereNull('checked_out_at')->first();
            if (! $pivot) {
                return ['error' => 'This room is not checked in on this booking.'];
            }
            $stay = Stay::whereKey($pivot->stay_id)->lockForUpdate()->first();
            if (! $stay || ! HotelClock::checkOutIsDue($stay->check_out)) {
                return ['error' => "Room {$room->room_number} can be extended once it is past 12:00 NN on its check-out day."];
            }
            if (! $new->gt($today)) {
                return ['error' => 'The new check-out date must be after today.'];
            }

            $this->availability->lockRoomTypesForAvailabilityCheck([$room->room_type_id]);
            $line = $this->lineFor($locked, $stay, $room);
            if ($reason = $this->whyNotAvailable($locked, $stay, $room, $line, $today, $new)) {
                return ['error' => $reason];
            }
            if ($clash = $this->heldByAnotherBooking($locked, $room, $today, $new)) {
                return ['error' => "Room {$room->room_number} is already assigned to Booking #{$clash} on those dates."];
            }

            $target = $this->moveRoom($locked, $stay, $room, $line, $new);

            $fresh = $locked->fresh();
            $fresh->syncSpanFromStays();
            $fresh->recordStayCheckOuts();
            $this->renumberStays($fresh);
            if ($bill = $fresh->billing()->first()) {
                $this->billing->refresh($fresh->fresh(), $bill);
            }

            return ['stay' => $target->fresh()];
        });
    }

    /** The room line this room was booked on; a booking from before room lines gets them from its rooms first. */
    private function lineFor(Booking $booking, Stay $stay, Room $room): BookingRoomLine
    {
        $lines = BookingRoomLine::where('booking_id', $booking->id)->where('stay_id', $stay->id)->get();
        if ($lines->isEmpty()) {
            $nights = $stay->nights();
            $rooms = $booking->rooms()->with('roomType')->wherePivot('stay_id', $stay->id)->get();
            foreach ($rooms->groupBy(fn ($r) => $r->room_type_id . '|' . RoomOptions::keyForRoom($r)) as $group) {
                $one = $group->first();
                $key = RoomOptions::keyForRoom($one);
                $lines->push(BookingRoomLine::create([
                    'booking_id' => $booking->id, 'stay_id' => $stay->id, 'room_type_id' => $one->room_type_id,
                    'room_type_name' => $one->roomType->name, 'quantity' => $group->count(), 'price_per_night' => $one->room_rate,
                    'number_of_nights' => $nights, 'subtotal' => round((float) $one->room_rate * $nights * $group->count(), 2),
                    'option_key' => RoomOptions::isStandard($key) ? null : $key,
                    'capacity' => RoomOptions::isStandard($key) ? null : $one->guest_capacity,
                ]));
            }
        }
        $room->loadMissing('roomType');
        $key = RoomOptions::keyForRoom($room);
        $sameType = $lines->where('room_type_id', $room->room_type_id)->where('quantity', '>', 0);

        return $sameType->first(fn ($l) => (RoomOptions::isStandard($l->option_key) ? RoomOptions::STANDARD : $l->option_key) === $key)
            ?? $sameType->first()
            ?? $lines->first();
    }

    /**
     * Every night from today to the new check-out, for the room type and for the booked option: what other bookings hold, plus this
     * booking's own other rooms, must leave one room free for this one.
     */
    private function whyNotAvailable(Booking $booking, Stay $stay, Room $room, BookingRoomLine $line, Carbon $today, Carbon $new): ?string
    {
        $type = RoomType::find($room->room_type_id);
        $option = RoomOptions::isStandard($line->option_key) ? RoomOptions::STANDARD : $line->option_key;

        foreach ([null, $option] as $key) {
            $capacity = $this->availability->sellableCapacity($type, $key);
            $others = $this->availability->occupancyByNight([$type->id], $today, $new, $booking->id, null, false, false, $key)[$type->id] ?? [];
            for ($night = $today->copy(); $night->lt($new); $night->addDay()) {
                $own = $this->ownRoomsOn($booking, $stay, $room, $type, $key, $night, $today);
                if (($others[$night->toDateString()] ?? 0) + $own + 1 > $capacity) {
                    $latest = $night->gt($today) ? " The latest it can check out is {$night->format('M j, Y')}." : '';

                    return "Room {$room->room_number} cannot stay until {$new->format('M j, Y')}: every {$type->name} room is taken on the night of {$night->format('M j')}.{$latest}";
                }
            }
        }

        return null;
    }

    /** The rooms this booking itself holds of the type (and option) on a night, without the room being extended. */
    private function ownRoomsOn(Booking $booking, Stay $stay, Room $room, RoomType $type, ?string $key, Carbon $night, Carbon $today): int
    {
        $count = 0;
        foreach ($booking->stays()->get() as $other) {
            if ($other->checked_out_at !== null || $other->no_show_at !== null) {
                continue;
            }
            $inHouse = $other->id === $stay->id || $booking->stateOf($other) === Stay::IN_HOUSE;
            $in = Carbon::parse($other->check_in->format('Y-m-d'));
            $out = Carbon::parse($other->check_out->format('Y-m-d'));
            if ($inHouse && $out->lte($today)) {
                $out = $today->copy()->addDay(); // past its check-out and still in the room: it holds today
            }
            if ($night->lt($in) || $night->gte($out)) {
                continue;
            }
            if ($inHouse) {
                $count += $booking->rooms()->with('roomType')->wherePivot('stay_id', $other->id)->wherePivotNull('checked_out_at')->get()
                    ->filter(fn ($r) => $r->id !== $room->id && $r->room_type_id === $type->id && ($key === null || RoomOptions::keyForRoom($r) === $key))
                    ->count();
            } else {
                $count += (int) BookingRoomLine::where('booking_id', $booking->id)->where('stay_id', $other->id)->where('room_type_id', $type->id)
                    ->when($key !== null, fn ($q) => RoomOptions::isStandard($key)
                        ? $q->where(fn ($w) => $w->whereNull('option_key')->orWhere('option_key', RoomOptions::STANDARD))
                        : $q->where('option_key', $key))
                    ->sum('quantity');
            }
        }

        return $count;
    }

    /** This very room already given to another confirmed booking on those dates (the id of that booking). */
    private function heldByAnotherBooking(Booking $booking, Room $room, Carbon $from, Carbon $to): ?int
    {
        return DB::table('booking_rooms as br')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->leftJoin('stays as s', 's.id', '=', 'br.stay_id')
            ->whereNull('b.deleted_at')->whereNull('br.checked_out_at')
            ->where('br.room_id', $room->id)->where('b.id', '!=', $booking->id)
            ->where('b.booking_status', Booking::STATUS_ACTIVE)
            ->whereRaw('COALESCE(s.check_in, b.check_in) < ?', [$to->format('Y-m-d H:i:s')])
            ->whereRaw('COALESCE(s.check_out, b.check_out) > ?', [$from->format('Y-m-d H:i:s')])
            ->value('b.id');
    }

    /** @return Stay the stay the room is on now */
    private function moveRoom(Booking $booking, Stay $stay, Room $room, BookingRoomLine $line, Carbon $new): Stay
    {
        $alone = DB::table('booking_rooms')->where('booking_id', $booking->id)->where('stay_id', $stay->id)->count() === 1;
        $sibling = $booking->stays()->get()->first(fn (Stay $s) => $s->id !== $stay->id && $s->checked_out_at === null
            && $s->checked_in_at !== null && $s->check_in->format('Y-m-d') === $stay->check_in->format('Y-m-d')
            && $s->check_out->format('Y-m-d') === $new->toDateString());

        if ($alone && ! $sibling) {
            $stay->forceFill(['check_out' => $new, 'extended_at' => now(), 'checkout_alerted_at' => null])->save();
            $this->repriceLines($stay);

            return $stay;
        }

        $target = $sibling;
        if (! $target) {
            $target = $stay->replicate();
            $target->forceFill(['check_out' => $new, 'position' => (int) $booking->stays()->max('position') + 1, 'extended_at' => now(), 'checkout_alerted_at' => null])->save();
        }

        DB::table('booking_rooms')->where('booking_id', $booking->id)->where('stay_id', $stay->id)->where('room_id', $room->id)->update(['stay_id' => $target->id]);
        DB::table('amenity_requests')->where('stay_id', $stay->id)->where('room_id', $room->id)->update(['stay_id' => $target->id]);

        $targetLine = BookingRoomLine::where('booking_id', $booking->id)->where('stay_id', $target->id)->where('room_type_id', $line->room_type_id)
            ->where('price_per_night', $line->price_per_night)->where(fn ($q) => $line->option_key === null ? $q->whereNull('option_key') : $q->where('option_key', $line->option_key))->first();
        if ($targetLine) {
            $targetLine->increment('quantity');
        } else {
            $targetLine = $line->replicate();
            $targetLine->forceFill(['stay_id' => $target->id, 'quantity' => 1])->save();
        }
        $line->decrement('quantity');
        if ($line->fresh()->quantity < 1) {
            DB::table('amenity_requests')->where('room_line_id', $line->id)->update(['room_line_id' => $targetLine->id]);
            $line->delete();
        }

        if ($alone) {
            // its last room joined the stay with the new dates: nothing is left of this one
            DB::table('amenity_requests')->where('stay_id', $stay->id)->update(['stay_id' => $target->id]);
            $stay->delete();
        } else {
            $this->repriceLines($stay);
        }
        $this->repriceLines($target);

        return $target;
    }

    /** A line's nights and subtotal follow its stay's dates, at the price booked. */
    private function repriceLines(Stay $stay): void
    {
        $nights = $stay->fresh()->nights();
        foreach (BookingRoomLine::where('stay_id', $stay->id)->get() as $line) {
            $line->update(['number_of_nights' => $nights, 'subtotal' => round((float) $line->price_per_night * $nights * (int) $line->quantity, 2)]);
        }
    }

    private function renumberStays(Booking $booking): void
    {
        foreach ($booking->stays()->get()->values() as $i => $stay) {
            if ((int) $stay->position !== $i + 1) {
                $stay->update(['position' => $i + 1]);
            }
        }
    }
}
