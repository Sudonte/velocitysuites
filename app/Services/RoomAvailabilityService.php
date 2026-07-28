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
     * checked-in Booking of this type.
     */
    public function availableCount(RoomType $roomType, Carbon $checkIn, Carbon $checkOut, ?int $excludingBookingId = null): int
    {
        $maintenanceCount = Room::where('room_type_id', $roomType->id)
            ->where('status', 'maintenance')
            ->count();

        $bookedCount = $this->overlappingBookings($roomType->id, $checkIn, $checkOut, $excludingBookingId)->count();

        return max(0, $this->totalInventory($roomType) - $maintenanceCount - $bookedCount);
    }

    public function isFullyBooked(RoomType $roomType, Carbon $checkIn, Carbon $checkOut): bool
    {
        return $this->availableCount($roomType, $checkIn, $checkOut) <= 0;
    }

    /**
     * Rooms of the booking's type that are physically available (not under
     * maintenance) and not already assigned to another overlapping
     * confirmed/checked-in booking - the pool the receptionist picks from
     * at check-in.
     */
    public function assignableRooms(Booking $booking): Collection
    {
        return Room::where('room_type_id', $booking->room_type_id)
            ->where('status', '!=', 'maintenance')
            ->whereDoesntHave('bookings', function ($q) use ($booking) {
                $q->whereIn('booking_status', ['confirmed', 'checked_in'])
                  ->where('id', '!=', $booking->id)
                  ->whereNotNull('room_id')
                  ->where(function ($dates) use ($booking) {
                      $dates->whereBetween('check_in', [$booking->check_in, $booking->check_out])
                            ->orWhereBetween('check_out', [$booking->check_in, $booking->check_out])
                            ->orWhere(function ($spanning) use ($booking) {
                                $spanning->where('check_in', '<=', $booking->check_in)
                                         ->where('check_out', '>=', $booking->check_out);
                            });
                  });
            })
            ->orderBy('room_number')
            ->get();
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
