<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Support\TestAccountScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "how many rooms of type X are free for dates Y-Z". Reservations never reserve inventory (guests can
 * request a type that's already fully booked - the receptionist rejects at conversion time); only confirmed Bookings (booking_status
 * confirmed/checked_in) consume it. Used by guest room-type browsing, the receptionist Convert-to-Booking inventory gate, and the
 * check-in room-assignment picker - one implementation, three call sites.
 *
 * Availability is counted PER NIGHT. A booking is one transaction made of stays; every stay has its own dates and its own room lines,
 * so a room type is occupied on a night by the room lines of every stay that includes that night, whatever transaction it belongs to.
 * A range is available as far as its WORST night: two stays that overlap on Oct 26 and both want a Deluxe need two free Deluxe rooms
 * on the night of Oct 26 (and one on the other nights each covers).
 */
class RoomAvailabilityService
{
    /**
     * Physical rooms of this type still in inventory (archived rooms are excluded), regardless of status. This is the denominator used
     * in "fully booked" messaging (e.g. "3 of 3 rooms booked") - a room under maintenance still counts as physical inventory, it just
     * can't currently be assigned (handled separately in availableCount()).
     */
    public function totalInventory(RoomType $roomType): int
    {
        return Room::notArchived()->where('room_type_id', $roomType->id)->count();
    }

    /**
     * Locks every given room_type row (ascending id order, so two overlapping multi-room-type operations locking the same set of rows
     * can never deadlock each other) and returns without doing anything else - the shared first step of "lock the actual contended
     * resource, then re-check availability before creating/converting anything that consumes real inventory." Must be called from
     * inside the same DB::transaction() that will go on to perform that creation/conversion; a plain SELECT ... FOR UPDATE with no
     * surrounding transaction releases its lock immediately and protects nothing.
     */
    public function lockRoomTypesForAvailabilityCheck(iterable $roomTypeIds): void
    {
        $ids = collect($roomTypeIds)->filter()->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return;
        }

        RoomType::whereIn('id', $ids)->lockForUpdate()->get();
    }

    /** Rooms bookable at all: inventory minus the ones under maintenance (unusable whatever the dates). */
    public function sellableCapacity(RoomType $roomType): int
    {
        $maintenance = Room::notArchived()->where('room_type_id', $roomType->id)->where('status', 'maintenance')->count();

        return max(0, $this->totalInventory($roomType) - $maintenance);
    }

    /**
     * How many rooms of this type are free for the given date range: sellable inventory minus the most rooms any single NIGHT of the
     * range already has taken by confirmed or checked-in bookings (their stays, each with its own dates).
     */
    public function availableCount(RoomType $roomType, Carbon $checkIn, Carbon $checkOut, ?int $excludingBookingId = null): int
    {
        $occupied = $this->occupancyByNight([$roomType->id], $checkIn, $checkOut, $excludingBookingId)[$roomType->id] ?? [];

        return max(0, $this->sellableCapacity($roomType) - ($occupied ? max($occupied) : 0));
    }

    /**
     * availableCount() for many room types at once: one inventory query, one maintenance query and one occupancy read in total.
     * Returns [room_type_id => available count]; every given type is present. Same rules as availableCount().
     *
     * @param  iterable<RoomType>  $roomTypes
     * @return array<int,int>
     */
    public function availableCounts(iterable $roomTypes, Carbon $checkIn, Carbon $checkOut): array
    {
        $ids = collect($roomTypes)->pluck('id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $inventory = Room::notArchived()->whereIn('room_type_id', $ids)
            ->selectRaw('room_type_id, COUNT(*) AS n')->groupBy('room_type_id')->pluck('n', 'room_type_id');
        $maintenance = Room::notArchived()->whereIn('room_type_id', $ids)->where('status', 'maintenance')
            ->selectRaw('room_type_id, COUNT(*) AS n')->groupBy('room_type_id')->pluck('n', 'room_type_id');
        $occupied = $this->occupancyByNight($ids->all(), $checkIn, $checkOut);

        $out = [];
        foreach ($ids as $id) {
            $peak = ! empty($occupied[$id]) ? max($occupied[$id]) : 0;
            $out[(int) $id] = max(0, (int) ($inventory[$id] ?? 0) - (int) ($maintenance[$id] ?? 0) - $peak);
        }

        return $out;
    }

    public function isFullyBooked(RoomType $roomType, Carbon $checkIn, Carbon $checkOut): bool
    {
        return $this->availableCount($roomType, $checkIn, $checkOut) <= 0;
    }

    /**
     * Rooms taken per night: [room_type_id => ['Y-m-d' => rooms]] for the nights in [$from, $to), counting the room lines of every
     * stay of every confirmed or checked-in booking (completed ones too when $statuses says so). A stay whose guests already left
     * (checked_out_at) frees its remaining nights unless $keepCheckedOutStays (reports); a stay still in house past its planned
     * check-out keeps occupying today (checkout is a manual desk action, never automatic). Bookings that predate room lines count
     * their room_type_id x rooms_requested over their own dates.
     *
     * @param  array<int,int>  $roomTypeIds
     * @param  array<int,string>|null  $statuses
     * @return array<int,array<string,int>>
     */
    public function occupancyByNight(array $roomTypeIds, Carbon $from, Carbon $to, ?int $excludingBookingId = null, ?array $statuses = null, bool $keepCheckedOutStays = false, bool $excludeTestAccounts = false): array
    {
        $statuses ??= [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN];
        $fromDay = Carbon::parse($from->format('Y-m-d'));
        $toDay = Carbon::parse($to->format('Y-m-d'));
        if ($roomTypeIds === [] || ! $fromDay->lt($toDay)) {
            return [];
        }
        $today = Carbon::parse(\App\Support\CheckInWindow::date()->format('Y-m-d'));
        $overdueApplies = $fromDay->lte($today);

        $lines = DB::table('booking_room_lines as l')
            ->join('bookings as b', 'b.id', '=', 'l.booking_id')
            ->leftJoin('stays as s', 's.id', '=', 'l.stay_id')
            ->whereNull('b.deleted_at')
            ->whereIn('b.booking_status', $statuses)
            ->whereIn('l.room_type_id', $roomTypeIds)
            ->when($excludingBookingId, fn ($q) => $q->where('b.id', '!=', $excludingBookingId))
            ->when($excludeTestAccounts, fn ($q) => $q->whereNotIn('b.id', TestAccountScope::testBookingIdsQuery()))
            ->selectRaw('l.room_type_id AS type_id, l.quantity AS qty, b.booking_status AS status, '
                . 's.id AS stay_id, s.checked_in_at AS stay_in_at, s.checked_out_at AS stay_out_at, s.no_show_at AS stay_no_show_at, '
                . 'COALESCE(s.check_in, b.check_in) AS s_in, COALESCE(s.check_out, b.check_out) AS s_out')
            ->get()
            ->map(fn ($r) => (array) $r);

        // Bookings from before room lines existed: their one room type and rooms_requested over the booking's own dates.
        $legacy = DB::table('bookings as b')
            ->whereNull('b.deleted_at')
            ->whereIn('b.booking_status', $statuses)
            ->whereIn('b.room_type_id', $roomTypeIds)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('booking_room_lines as l')->whereColumn('l.booking_id', 'b.id'))
            ->when($excludingBookingId, fn ($q) => $q->where('b.id', '!=', $excludingBookingId))
            ->when($excludeTestAccounts, fn ($q) => $q->whereNotIn('b.id', TestAccountScope::testBookingIdsQuery()))
            ->selectRaw('b.room_type_id AS type_id, b.rooms_requested AS qty, b.booking_status AS status, '
                . 'NULL AS stay_id, NULL AS stay_in_at, NULL AS stay_out_at, NULL AS stay_no_show_at, b.check_in AS s_in, b.check_out AS s_out')
            ->get()
            ->map(fn ($r) => (array) $r);

        $out = [];
        foreach ($lines->concat($legacy) as $r) {
            if (($r['stay_no_show_at'] ?? null) !== null && ! $keepCheckedOutStays) {
                continue; // a date range marked as a no-show no longer holds its rooms
            }
            if ($r['stay_out_at'] !== null && ! $keepCheckedOutStays) {
                continue; // that stay's guests have left: the nights they did not use are free again
            }
            $in = Carbon::parse(substr((string) $r['s_in'], 0, 10));
            $outDay = Carbon::parse(substr((string) $r['s_out'], 0, 10));
            // Still in the room after the planned check-out: occupying today.
            $inHouse = $r['status'] === Booking::STATUS_CHECKED_IN && ($r['stay_id'] === null || $r['stay_in_at'] !== null);
            if ($overdueApplies && $inHouse && $outDay->lte($today)) {
                $outDay = $today->copy()->addDay();
            }
            $start = $in->gt($fromDay) ? $in : $fromDay;
            $end = $outDay->lt($toDay) ? $outDay : $toDay;
            for ($d = $start->copy(); $d->lt($end); $d->addDay()) {
                $key = $d->toDateString();
                $out[(int) $r['type_id']][$key] = ($out[(int) $r['type_id']][$key] ?? 0) + (int) $r['qty'];
            }
        }

        return $out;
    }

    /**
     * Availability for ALL the stays of one transaction together, night by night: the rooms every stay asks for (stays may overlap, so
     * their rooms add up on the nights they share) on top of what other transactions already hold. Returns null when everything fits,
     * or ['stay' => n, 'field' => ..., 'message' => ...] naming the stay and the night that does not.
     *
     * Two stays with the very same check-in AND check-out are refused outright (the guest should add rooms to that stay instead).
     * A one-stay transaction gets the same wording it always did.
     *
     * @param  array<int,array{check_in: Carbon, check_out: Carbon, lines: array<int,array{room_type: RoomType, quantity: int}>}>  $stays
     */
    public function staysShortfall(array $stays, ?int $excludingBookingId = null): ?array
    {
        $stays = array_values($stays);

        foreach ($stays as $i => $a) {
            foreach ($stays as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                if ($a['check_in']->format('Y-m-d') === $b['check_in']->format('Y-m-d') && $a['check_out']->format('Y-m-d') === $b['check_out']->format('Y-m-d')) {
                    $label = $a['check_in']->format('M j') . ' - ' . $a['check_out']->format('M j');

                    return ['stay' => $j + 1, 'field' => 'stays', 'message' => "You already added a stay for {$label}. Pick different dates or add rooms to that stay."];
                }
            }
        }

        $byType = [];
        foreach ($stays as $i => $stay) {
            foreach ($stay['lines'] as $line) {
                $id = $line['room_type']->id;
                $byType[$id]['type'] = $line['room_type'];
                $byType[$id]['stays'][$i] = ($byType[$id]['stays'][$i] ?? 0) + (int) $line['quantity'];
            }
        }

        foreach ($byType as $typeId => $entry) {
            /** @var RoomType $type */
            $type = $entry['type'];
            if ($type->status !== 'active') {
                return ['stay' => (int) array_key_first($entry['stays']) + 1, 'field' => 'room_type_id', 'message' => "{$type->name} is not currently offered."];
            }
            $capacity = $this->sellableCapacity($type);
            $firstIn = collect(array_keys($entry['stays']))->map(fn ($i) => $stays[$i]['check_in'])->min();
            $lastOut = collect(array_keys($entry['stays']))->map(fn ($i) => $stays[$i]['check_out'])->max();
            $others = $this->occupancyByNight([$typeId], $firstIn, $lastOut, $excludingBookingId)[$typeId] ?? [];
            $own = [];
            foreach ($entry['stays'] as $i => $qty) {
                $in = Carbon::parse($stays[$i]['check_in']->format('Y-m-d'));
                $out = Carbon::parse($stays[$i]['check_out']->format('Y-m-d'));
                for ($d = $in->copy(); $d->lt($out); $d->addDay()) {
                    $night = $d->toDateString();
                    $own[$night] = ($own[$night] ?? 0) + $qty;
                    if (($others[$night] ?? 0) + $own[$night] > $capacity) {
                        $free = max(0, $capacity - ($others[$night] ?? 0));
                        $name = $type->name;
                        $date = $d->format('M j');
                        if (count($stays) === 1) {
                            $message = $free > 0
                                ? "No more {$name} rooms available for these dates ({$free} available)."
                                : "{$name} is fully booked for these dates.";

                            return ['stay' => 1, 'field' => 'rooms_requested', 'message' => $message, 'type_name' => $name, 'needed' => $own[$night], 'free' => $free];
                        }
                        $message = $free > 0
                            ? "Not enough {$name} rooms on {$date} across your stays (needs {$own[$night]}, only {$free} free that night)."
                            : "{$name} is fully booked on {$date}.";

                        return ['stay' => $i + 1, 'field' => 'stays', 'message' => $message, 'type_name' => $name, 'needed' => $own[$night], 'free' => $free];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Normalizes either request shape - a genuine multi-room-type `rooms` array (each a `room_type_id`/`quantity` pair) or the legacy
     * singular room_type_id/rooms_requested pair - into an array of ['room_type' => RoomType, 'quantity' => int] lines, then validates
     * every line's own availability for the given dates. Returns null on success (the resolved lines are written into &$roomLines) or a
     * single guest-facing error string on the FIRST line that fails - all-or-nothing, the caller must not create anything on a partial
     * failure. Shared by every receptionist creation flow that accepts a multi-room-type selection (New Booking, New Reservation,
     * Walk-In Check-In) so the three can never validate availability differently.
     *
     * Quantities are summed PER DISTINCT room_type_id before any availability check runs, so the same room type across two rows can
     * never pass availableCount() twice against the same free inventory.
     */
    public function resolveAndValidateRoomLines(
        array $rawRooms,
        ?int $legacyRoomTypeId,
        ?int $legacyRoomsRequested,
        Carbon $checkIn,
        Carbon $checkOut,
        ?array &$roomLines = null
    ): ?string {
        $rawLines = !empty($rawRooms) ? $rawRooms : [[
            'room_type_id' => $legacyRoomTypeId,
            'quantity' => $legacyRoomsRequested ?? 1,
        ]];

        $quantitiesByRoomTypeId = [];
        foreach ($rawLines as $line) {
            $roomTypeId = $line['room_type_id'] ?? null;
            if (!$roomTypeId) {
                return 'One of the selected room types no longer exists.';
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $quantitiesByRoomTypeId[$roomTypeId] = ($quantitiesByRoomTypeId[$roomTypeId] ?? 0) + $quantity;
        }

        $roomLines = [];
        foreach ($quantitiesByRoomTypeId as $roomTypeId => $quantity) {
            $roomType = RoomType::find($roomTypeId);
            if (!$roomType) {
                return 'One of the selected room types no longer exists.';
            }
            if ($roomType->status !== 'active') {
                return "{$roomType->name} is not currently available.";
            }

            $available = $this->availableCount($roomType, $checkIn, $checkOut);
            if ($available < $quantity) {
                return $quantity > 1
                    ? "Not enough {$roomType->name} rooms available for these dates (needs {$quantity}, only {$available} free)."
                    : "{$roomType->name} is fully booked for the requested dates.";
            }

            $roomLines[] = ['room_type' => $roomType, 'quantity' => $quantity];
        }

        return null;
    }

    /**
     * Rooms of the booking's type that are physically available (not under maintenance) and not held by another stay - the pool the
     * receptionist picks from at check-in (Receptionist\CheckInController). A booking can request more than one room
     * (rooms_requested); the caller is responsible for having the receptionist pick that many distinct rooms from this list.
     */
    public function assignableRooms(Booking $booking, ?Stay $stay = null): Collection
    {
        return $this->assignableRoomsOfType($booking->room_type_id, $booking, $stay);
    }

    /**
     * Same as assignableRooms() above, but for an arbitrary room type - the building block for a multi-room-type booking's per-line
     * assignment, run once per distinct room type. $stay is the stay being checked in (its own dates decide the window); without it the
     * booking's overall span is used.
     */
    public function assignableRoomsOfType(int $roomTypeId, Booking $booking, ?Stay $stay = null): Collection
    {
        // Rooms are only ever assigned at check-in, i.e. NOW - even for a guest arriving ahead of their booked
        // date - so the stay is taken to start today (or on its booked date if that already passed).
        $hotelToday = \App\Support\CheckInWindow::date();
        $in = $stay?->check_in ?? $booking->check_in;
        $out = $stay?->check_out ?? $booking->check_out;
        $from = $in->lt($hotelToday) ? $in : $hotelToday;

        return $this->freeRoomsOfType($roomTypeId, $from, $out, $booking->id, $stay && ! $stay->isVirtual() ? $stay->id : null);
    }

    /**
     * Rooms of a type a guest can be put into right now for a stay ending $checkOut - the Walk-in Check-in form's room picker (and the
     * same rule the Check In panel uses). A room is NOT free while:
     *  - anyone is checked in to it (whatever their dates - checkout is manual, so a guest is in the room until the receptionist
     *    checks them out), or
     *  - it's pre-assigned to a confirmed booking whose stay (that stay's own dates) overlaps.
     * Maintenance and archived rooms are never offered. $excludingBookingId leaves that booking's own rooms out of the exclusion;
     * with $excludingStayId only that stay's own rooms - its OTHER stays still hold theirs.
     */
    public function freeRoomsOfType(int $roomTypeId, Carbon $from, Carbon $checkOut, ?int $excludingBookingId = null, ?int $excludingStayId = null): Collection
    {
        $taken = DB::table('booking_rooms as br')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->leftJoin('stays as s', 's.id', '=', 'br.stay_id')
            ->whereNull('b.deleted_at')
            // A room individually checked out of a multi-room stay is free again at once, even while its sibling rooms' booking is still CHECKED_IN.
            ->whereNull('br.checked_out_at')
            ->when($excludingBookingId, function ($q) use ($excludingBookingId, $excludingStayId) {
                $q->where(function ($q) use ($excludingBookingId, $excludingStayId) {
                    $q->where('b.id', '!=', $excludingBookingId);
                    if ($excludingStayId) {
                        $q->orWhere(fn ($q) => $q->whereNotNull('br.stay_id')->where('br.stay_id', '!=', $excludingStayId));
                    }
                });
            })
            ->where(function ($q) use ($from, $checkOut) {
                $q->where('b.booking_status', Booking::STATUS_CHECKED_IN)
                    ->orWhere(function ($q) use ($from, $checkOut) {
                        $q->where('b.booking_status', Booking::STATUS_ACTIVE)
                            ->whereRaw('COALESCE(s.check_in, b.check_in) < ?', [$checkOut->format('Y-m-d H:i:s')])
                            ->whereRaw('COALESCE(s.check_out, b.check_out) > ?', [$from->format('Y-m-d H:i:s')]);
                    });
            })
            ->pluck('br.room_id');

        return Room::notArchived()->where('room_type_id', $roomTypeId)
            ->where('status', '!=', 'maintenance')
            ->whereNotIn('id', $taken->unique()->all())
            ->orderBy('room_number')
            ->get();
    }

    /**
     * Assignable rooms grouped by room_type_id, one entry per distinct room type the stay needs rooms for - the stay's own room lines
     * (a booking without lines falls back to its single room type). Keys are strings (room_type_id) to match the shape the check-in
     * panel's form field names naturally work with.
     */
    public function assignableRoomsByLine(Booking $booking, ?Stay $stay = null): Collection
    {
        $lines = $this->linesOfStay($booking, $stay);
        if ($lines->isEmpty()) {
            return collect([(string) $booking->room_type_id => $this->assignableRoomsOfType($booking->room_type_id, $booking, $stay)]);
        }

        return $lines->mapWithKeys(
            fn ($line) => [(string) $line->room_type_id => $this->assignableRoomsOfType($line->room_type_id, $booking, $stay)]
        );
    }

    /** The room lines that belong to one stay (a one-stay booking: all of them; a row with no stay yet belongs to the first stay). */
    public function linesOfStay(Booking $booking, ?Stay $stay = null): Collection
    {
        $lines = $booking->roomLines()->get();
        if ($stay === null || $booking->stayCount() === 1) {
            return $lines;
        }
        $firstId = $booking->stayList()->first()?->id;

        return $lines->filter(fn ($l) => ($l->stay_id ?? $firstId) === $stay->id)->values();
    }

    /**
     * Validates a receptionist's room picks against a fresh assignableRooms() query (never trusting a possibly-stale list the caller's
     * form was rendered with) and ties them to the booking through the booking_rooms pivot for the stay, keeping bookings.room_id in
     * sync as the first assigned room. Throws a 422 HttpException with a guest-safe message if any pick is no longer available.
     */
    public function assignRooms(Booking $booking, array $roomIds, ?Stay $stay = null): Collection
    {
        $assignable = $this->assignableRooms($booking, $stay)->keyBy('id');
        $rooms = collect($roomIds)->map(fn ($id) => $assignable->get((int) $id));

        if ($rooms->contains(null)) {
            abort(422, 'One or more selected rooms are no longer available: they are not a free '
                . ($booking->roomType->name ?? 'matching') . ' room for these dates. Please try again.');
        }

        $this->storeAssignment($booking, $rooms, $stay);

        return $rooms;
    }

    /**
     * assignRooms()'s multi-room-type-aware counterpart - $roomIdsByType is ['<room_type_id>' => [room_id, ...]], one entry per line
     * returned by assignableRoomsByLine(). Validates every line independently against ITS OWN type's assignable rooms, and that every
     * line got exactly its own required quantity of distinct rooms. Writes the stay's rooms in one go.
     */
    public function assignRoomsForLines(Booking $booking, array $roomIdsByType, ?Stay $stay = null): Collection
    {
        $lines = $this->linesOfStay($booking, $stay);
        $requiredByType = $lines->isEmpty()
            ? collect([(string) $booking->room_type_id => $booking->rooms_requested])
            : $lines->mapWithKeys(fn ($line) => [(string) $line->room_type_id => $line->quantity]);

        $allRooms = collect();

        foreach ($requiredByType as $roomTypeId => $requiredQuantity) {
            $picked = collect($roomIdsByType[$roomTypeId] ?? []);
            if ($picked->count() !== (int) $requiredQuantity) {
                $roomType = RoomType::find((int) $roomTypeId);
                abort(422, "This booking needs exactly {$requiredQuantity} " . ($roomType->name ?? 'room')
                    . ' room(s) assigned - selected ' . $picked->count() . '.');
            }

            $assignable = $this->assignableRoomsOfType((int) $roomTypeId, $booking, $stay)->keyBy('id');
            $rooms = $picked->map(fn ($id) => $assignable->get((int) $id));

            if ($rooms->contains(null)) {
                $roomType = RoomType::find((int) $roomTypeId);
                abort(422, 'One or more selected rooms are no longer available: they are not a free '
                    . ($roomType->name ?? 'matching') . ' room for these dates. Please try again.');
            }

            $allRooms = $allRooms->merge($rooms);
        }

        $this->storeAssignment($booking, $allRooms, $stay);

        return $allRooms;
    }

    /**
     * Replaces the rooms of ONE stay (never the other stays' rooms; with no stay given, all of the booking's rooms - the old
     * behavior) and keeps bookings.room_id on the first assigned room.
     */
    private function storeAssignment(Booking $booking, Collection $rooms, ?Stay $stay): void
    {
        $booking->ensureStays();
        $stayId = $stay === null || $stay->isVirtual() ? $booking->stayList()->first()?->id : $stay->id;

        $delete = DB::table('booking_rooms')->where('booking_id', $booking->id);
        if ($stay !== null) {
            $delete->where('stay_id', $stayId);
        }
        $delete->delete();
        foreach ($rooms as $room) {
            $booking->rooms()->attach($room->id, ['stay_id' => $stayId]);
        }
        if ($booking->room_id === null || $stay === null) {
            $booking->update(['room_id' => $rooms->first()->id]);
        }
        $booking->unsetRelation('rooms');
    }

    /**
     * Per-room-type utilization over an arbitrary date range: booked room-nights (every stay of every confirmed, in-house or
     * checked-out booking, clipped to the [from,to] window) divided by total possible room-nights in the window (every physical room
     * of that type x the number of days). Checked-out stays count - they occupied the rooms; counting only live bookings made every
     * past period read 0%. Test accounts never count.
     */
    public function utilizationByRoomType(Carbon $from, Carbon $to): Collection
    {
        $periodDays = max(1, $from->diffInDays($to));
        $types = RoomType::orderBy('name')->get();
        $occupied = $this->occupancyByNight(
            $types->pluck('id')->all(), $from, $to, null,
            [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED], true, true
        );

        return $types->map(function (RoomType $roomType) use ($occupied, $periodDays) {
            $totalRoomNights = $this->totalInventory($roomType) * $periodDays;
            $bookedRoomNights = array_sum($occupied[$roomType->id] ?? []);

            return [
                'room_type' => $roomType->name,
                'utilization' => $totalRoomNights > 0 ? round(min(100, $bookedRoomNights / $totalRoomNights * 100), 1) : 0.0,
            ];
        });
    }
}
