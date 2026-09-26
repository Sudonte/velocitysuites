<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "how many rooms of type X are free for dates
 * Y-Z". Reservations never reserve inventory (guests can request a type
 * that's already fully booked - the receptionist rejects at conversion
 * time); only confirmed Bookings (booking_status confirmed/checked_in)
 * consume it. Used by guest room-type browsing, the receptionist
 * Convert-to-Booking inventory gate, and the check-in room-assignment
 * picker - one implementation, three call sites.
 */
class RoomAvailabilityService
{
    /**
     * Total physical rooms of this type, regardless of status. This is the
     * denominator used in "fully booked" messaging (e.g. "3 of 3 rooms
     * booked") - a room under maintenance still counts as physical
     * inventory, it just can't currently be assigned (handled separately
     * in availableCount()).
     */
    public function totalInventory(RoomType $roomType): int
    {
        return Room::where('room_type_id', $roomType->id)->count();
    }

    /**
     * Locks every given room_type row (ascending id order, so two
     * overlapping multi-room-type operations locking the same set of rows
     * can never deadlock each other) and returns without doing anything
     * else - the shared first step of "lock the actual contended resource,
     * then re-check availability before creating/converting anything that
     * consumes real inventory." Must be called from inside the same
     * DB::transaction() that will go on to perform that creation/
     * conversion; a plain SELECT ... FOR UPDATE with no surrounding
     * transaction releases its lock immediately and protects nothing.
     * Used by every call site that creates a Booking (which consumes real
     * inventory) - DirectBookingService::create(), ReservationWorkflowService::
     * convertToBooking()/tryAutoConvert(), Receptionist\BookingController::
     * store() - never by anything that only creates a Reservation, since a
     * Reservation never reserves inventory in the first place (see this
     * class's own top-of-file doc).
     */
    public function lockRoomTypesForAvailabilityCheck(iterable $roomTypeIds): void
    {
        $ids = collect($roomTypeIds)->filter()->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return;
        }

        RoomType::whereIn('id', $ids)->lockForUpdate()->get();
    }

    /**
     * How many rooms of this type are free for the given date range: total
     * inventory, minus rooms under maintenance (unusable regardless of
     * dates), minus rooms already consumed by an overlapping confirmed or
     * checked-in Booking of this type. Each overlapping booking consumes
     * its full rooms_requested count, not just 1 - this is what reserves
     * the right amount of inventory for a multi-room booking from the
     * moment it's confirmed, not just after check-in assigns specific
     * rooms.
     */
    public function availableCount(RoomType $roomType, Carbon $checkIn, Carbon $checkOut, ?int $excludingBookingId = null): int
    {
        $maintenanceCount = Room::where('room_type_id', $roomType->id)
            ->where('status', 'maintenance')
            ->count();

        $bookedCount = (int) $this->overlappingBookings($roomType->id, $checkIn, $checkOut, $excludingBookingId)->sum('rooms_requested');

        return max(0, $this->totalInventory($roomType) - $maintenanceCount - $bookedCount);
    }

    public function isFullyBooked(RoomType $roomType, Carbon $checkIn, Carbon $checkOut): bool
    {
        return $this->availableCount($roomType, $checkIn, $checkOut) <= 0;
    }

    /**
     * Normalizes either request shape - a genuine multi-room-type `rooms`
     * array (each a `room_type_id`/`quantity` pair) or the legacy singular
     * room_type_id/rooms_requested pair - into an array of
     * ['room_type' => RoomType, 'quantity' => int] lines, then validates
     * every line's own availability for the given dates. Returns null on
     * success (the resolved lines are written into &$roomLines) or a
     * single guest-facing error string on the FIRST line that fails - all-
     * or-nothing, the caller must not create anything on a partial
     * failure. Shared by every receptionist creation flow that accepts a
     * multi-room-type selection (New Booking, New Reservation, Walk-In
     * Check-In - previously each was hard-limited to exactly one room
     * type, unlike the guest-facing API's own `rooms[]` support) so the
     * three can never validate availability differently. Mirrors
     * DirectBookingService's identical guest-facing contract
     * (validateRoomLinesAvailability()) but returns a friendly string
     * instead of throwing, matching these controllers' existing
     * back()->withInput()->with('error', ...) convention.
     *
     * Quantities are summed PER DISTINCT room_type_id before any
     * availability check runs - a receptionist accidentally (or a raw
     * crafted request deliberately) submitting the same room type across
     * two separate rows (e.g. "Deluxe x2" twice) must never pass
     * availableCount() twice against the same free inventory and end up
     * assigning more rooms of that type than actually exist. This also
     * means the resulting $roomLines/booking_room_lines never contain two
     * rows for the same room type.
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
     * Rooms of the booking's type that are physically available (not under
     * maintenance) and not already assigned (via the booking_rooms pivot)
     * to another overlapping booking that's still confirmed or already
     * checked_in - the pool the receptionist picks from at check-in
     * (Receptionist\CheckInController). Confirmed (not just checked_in)
     * bookings are excluded too, defensively: room assignment now only
     * happens atomically with check-in, but this still guards against any
     * booking left with a pre-assigned room from before that was the case.
     * The booking's own already-assigned room(s) stay selectable (excluded
     * from the exclusion) so re-opening its own panel doesn't show its
     * current room as unavailable. A booking can request more than one
     * room (rooms_requested); the caller is responsible for having the
     * receptionist pick that many distinct rooms from this list.
     */
    public function assignableRooms(Booking $booking): Collection
    {
        return $this->assignableRoomsOfType($booking->room_type_id, $booking);
    }

    /**
     * Same as assignableRooms() above, but for an arbitrary room type
     * rather than always $booking->room_type_id - the building block for a
     * genuine multi-room-type booking's per-line assignment (see
     * assignableRoomsByLine()/assignRoomsForLines() below), since a
     * multi-room-type booking needs this same free/occupied query run once
     * per distinct room type, not just its own (first-line) type.
     */
    public function assignableRoomsOfType(int $roomTypeId, Booking $booking): Collection
    {
        return Room::where('room_type_id', $roomTypeId)
            ->where('status', '!=', 'maintenance')
            ->whereDoesntHave('assignedBookings', function ($q) use ($booking) {
                $q->whereIn('bookings.booking_status', [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN])
                  ->where('bookings.id', '!=', $booking->id);
                $this->occupiesRoom($q, $booking->check_in, $booking->check_out, 'bookings.');
            })
            ->orderBy('room_number')
            ->get();
    }

    /**
     * Assignable rooms grouped by room_type_id, one entry per distinct room
     * type the booking actually needs rooms for - real booking_room_lines
     * when this is a genuine multi-room-type booking, or a single
     * [room_type_id => rooms] entry keyed off the booking's own
     * room_type_id for the legacy single-room-type case. Keys are strings
     * (room_type_id) to match the shape the check-in panel's Blade view/JS
     * naturally works with (form field names, not array indices).
     */
    public function assignableRoomsByLine(Booking $booking): Collection
    {
        $lines = $booking->roomLines()->get();
        if ($lines->isEmpty()) {
            return collect([(string) $booking->room_type_id => $this->assignableRoomsOfType($booking->room_type_id, $booking)]);
        }

        return $lines->mapWithKeys(
            fn ($line) => [(string) $line->room_type_id => $this->assignableRoomsOfType($line->room_type_id, $booking)]
        );
    }

    /**
     * Validates a receptionist's room picks against a fresh
     * assignableRooms() query (never trusting a possibly-stale list the
     * caller's form was rendered with) and ties them to the booking via
     * the booking_rooms pivot, keeping bookings.room_id in sync as the
     * first assigned room. Used by check-in (Receptionist\CheckInController
     * - its own booking_status/room-status/notification side effects are
     * applied by the caller afterward). Throws a 422 HttpException with a
     * guest-safe message if any pick is no longer available - callers
     * don't need their own availability re-check on top of this.
     */
    public function assignRooms(Booking $booking, array $roomIds): Collection
    {
        $assignable = $this->assignableRooms($booking)->keyBy('id');
        $rooms = collect($roomIds)->map(fn ($id) => $assignable->get((int) $id));

        if ($rooms->contains(null)) {
            abort(422, 'One or more selected rooms are no longer available: they are not a free '
                . ($booking->roomType->name ?? 'matching') . ' room for these dates. Please try again.');
        }

        $booking->rooms()->sync($rooms->pluck('id'));
        $booking->update(['room_id' => $rooms->first()->id]);

        return $rooms;
    }

    /**
     * assignRooms()'s multi-room-type-aware counterpart - $roomIdsByType is
     * ['<room_type_id>' => [room_id, room_id, ...]], one entry per line
     * returned by assignableRoomsByLine(). Validates every line
     * independently against ITS OWN type's assignable rooms (never a
     * different line's rooms bleeding into another's pick), and that every
     * line got exactly its own required quantity of distinct rooms. Syncs
     * every picked room across every line onto the booking in one pivot
     * write, and sets bookings.room_id to the very first line's first
     * picked room (matches assignRooms()'s existing "first assigned room"
     * convention for the many display-only call sites that only need "the
     * room" as a reasonable simplification).
     */
    public function assignRoomsForLines(Booking $booking, array $roomIdsByType): Collection
    {
        $requiredByType = $booking->roomLines()->get()->isEmpty()
            ? collect([(string) $booking->room_type_id => $booking->rooms_requested])
            : $booking->roomLines()->get()->mapWithKeys(fn ($line) => [(string) $line->room_type_id => $line->quantity]);

        $allRooms = collect();

        foreach ($requiredByType as $roomTypeId => $requiredQuantity) {
            $picked = collect($roomIdsByType[$roomTypeId] ?? []);
            if ($picked->count() !== (int) $requiredQuantity) {
                $roomType = RoomType::find((int) $roomTypeId);
                abort(422, "This booking needs exactly {$requiredQuantity} " . ($roomType->name ?? 'room')
                    . ' room(s) assigned - selected ' . $picked->count() . '.');
            }

            $assignable = $this->assignableRoomsOfType((int) $roomTypeId, $booking)->keyBy('id');
            $rooms = $picked->map(fn ($id) => $assignable->get((int) $id));

            if ($rooms->contains(null)) {
                $roomType = RoomType::find((int) $roomTypeId);
                abort(422, 'One or more selected rooms are no longer available: they are not a free '
                    . ($roomType->name ?? 'matching') . ' room for these dates. Please try again.');
            }

            $allRooms = $allRooms->merge($rooms);
        }

        $booking->rooms()->sync($allRooms->pluck('id'));
        $booking->update(['room_id' => $allRooms->first()->id]);

        return $allRooms;
    }

    /**
     * Per-room-type utilization over an arbitrary date range: booked
     * room-nights (each overlapping confirmed/checked_in booking's stay,
     * clipped to the [from,to] window and multiplied by rooms_requested)
     * divided by total possible room-nights in the window (every physical
     * room of that type x the number of days). Reuses the exact overlap
     * query availableCount() already relies on - not a separate metric,
     * just aggregated over a period instead of checked at a single
     * moment/booking.
     */
    public function utilizationByRoomType(Carbon $from, Carbon $to): Collection
    {
        $periodDays = max(1, $from->diffInDays($to));

        return RoomType::orderBy('name')->get()->map(function (RoomType $roomType) use ($from, $to, $periodDays) {
            $totalRoomNights = $this->totalInventory($roomType) * $periodDays;

            $bookedRoomNights = $this->overlappingBookings($roomType->id, $from, $to)
                ->get()
                ->sum(function (Booking $booking) use ($from, $to) {
                    $overlapStart = $booking->check_in->gt($from) ? $booking->check_in : $from;
                    $overlapEnd = $booking->check_out->lt($to) ? $booking->check_out : $to;
                    $nights = max(0, $overlapStart->diffInDays($overlapEnd));

                    return $nights * $booking->rooms_requested;
                });

            return [
                'room_type' => $roomType->name,
                'utilization' => $totalRoomNights > 0 ? round(min(100, $bookedRoomNights / $totalRoomNights * 100), 1) : 0.0,
            ];
        });
    }

    /**
     * Bookings of this type whose confirmed/checked_in stay overlaps the
     * given date range - the raw overlap query shared by availableCount().
     */
    private function overlappingBookings(int $roomTypeId, Carbon $checkIn, Carbon $checkOut, ?int $excludingBookingId = null)
    {
        $query = Booking::where('room_type_id', $roomTypeId)
            ->whereIn('booking_status', [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN])
            ->when($excludingBookingId, fn ($q) => $q->where('id', '!=', $excludingBookingId));

        $this->occupiesRoom($query, $checkIn, $checkOut);

        return $query;
    }

    /**
     * The actual "does a booking occupy this room/room-type for
     * [checkIn, checkOut)" test, shared by assignableRooms() (queried
     * through the booking_rooms pivot join, hence $columnPrefix) and
     * overlappingBookings() (queried directly against bookings).
     *
     * Standard half-open-interval overlap (existing.check_in < new.check_out
     * AND existing.check_out > new.check_in) - with one exception: checkout
     * is a manual receptionist action here, never automatic, so a
     * CHECKED_IN booking whose recorded check_out has already passed (the
     * guest hasn't actually left yet) still occupies the room. Without this,
     * an overdue guest's room silently became "assignable" again the moment
     * the calendar rolled past their planned checkout, even though nobody
     * had checked them out - letting a receptionist assign a second guest
     * into a room that was still physically occupied. Only applied when the
     * candidate stay starts today or earlier (an actual check-in), so it
     * can't wrongly block an unrelated future date-range availability
     * lookup (e.g. a guest browsing next month's rates) just because
     * today's occupant happens to be overdue.
     */
    private function occupiesRoom($query, Carbon $checkIn, Carbon $checkOut, string $columnPrefix = ''): void
    {
        $checkInCol = $columnPrefix . 'check_in';
        $checkOutCol = $columnPrefix . 'check_out';
        $statusCol = $columnPrefix . 'booking_status';

        $query->where($checkInCol, '<', $checkOut)
              ->where(function ($end) use ($checkIn, $checkOutCol, $statusCol) {
                  $end->where($checkOutCol, '>', $checkIn);

                  if ($checkIn->lte(Carbon::today())) {
                      $end->orWhere(function ($overstay) use ($checkOutCol, $statusCol) {
                          $overstay->where($statusCol, Booking::STATUS_CHECKED_IN)
                                   ->where($checkOutCol, '<=', Carbon::today());
                      });
                  }
              });
    }
}

