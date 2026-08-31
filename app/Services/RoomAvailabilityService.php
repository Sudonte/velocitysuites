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
     * Rooms of the booking's type that are physically available (not under
     * maintenance) and not already assigned (via the booking_rooms pivot)
     * to another overlapping booking that's still confirmed or already
     * checked_in - the pool the receptionist picks from, whether assigning
     * ahead of arrival (Receptionist\BookingController, any time after
     * confirmation) or at check-in (Receptionist\CheckInController).
     * Confirmed (not just checked_in) bookings are excluded here too -
     * once early/pre-arrival assignment exists, two different confirmed
     * bookings for overlapping dates must not be able to claim the same
     * physical room just because neither guest has arrived yet. The
     * booking's own already-assigned room(s) stay selectable (excluded
     * from the exclusion) so re-opening its own panel doesn't show its
     * current room as unavailable. A booking can request more than one
     * room (rooms_requested); the caller is responsible for having the
     * receptionist pick that many distinct rooms from this list.
     */
    public function assignableRooms(Booking $booking): Collection
    {
        return Room::where('room_type_id', $booking->room_type_id)
            ->where('status', '!=', 'maintenance')
            ->whereDoesntHave('assignedBookings', function ($q) use ($booking) {
                $q->whereIn('bookings.booking_status', ['confirmed', 'checked_in'])
                  ->where('bookings.id', '!=', $booking->id)
                  ->where(function ($dates) use ($booking) {
                      $dates->whereBetween('bookings.check_in', [$booking->check_in, $booking->check_out])
                            ->orWhereBetween('bookings.check_out', [$booking->check_in, $booking->check_out])
                            ->orWhere(function ($spanning) use ($booking) {
                                $spanning->where('bookings.check_in', '<=', $booking->check_in)
                                         ->where('bookings.check_out', '>=', $booking->check_out);
                            });
                  });
            })
            ->orderBy('room_number')
            ->get();
    }

    /**
     * Validates a receptionist's room picks against a fresh
     * assignableRooms() query (never trusting a possibly-stale list the
     * caller's form was rendered with) and ties them to the booking via
     * the booking_rooms pivot, keeping bookings.room_id in sync as the
     * first assigned room. Shared by early assignment
     * (Receptionist\BookingController - confirmed, pre-arrival) and
     * check-in (Receptionist\CheckInController - the same operation, plus
     * its own booking_status/room-status/notification side effects the
     * caller applies afterward). Throws a 422 HttpException with a
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
        return Booking::where('room_type_id', $roomTypeId)
            ->whereIn('booking_status', ['confirmed', 'checked_in'])
            ->when($excludingBookingId, fn ($q) => $q->where('id', '!=', $excludingBookingId))
            ->where(function ($dates) use ($checkIn, $checkOut) {
                $dates->whereBetween('check_in', [$checkIn, $checkOut])
                      ->orWhereBetween('check_out', [$checkIn, $checkOut])
                      ->orWhere(function ($spanning) use ($checkIn, $checkOut) {
                          $spanning->where('check_in', '<=', $checkIn)
                                   ->where('check_out', '>=', $checkOut);
                      });
            });
    }
}

