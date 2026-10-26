<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\Stay;

/**
 * Paid amenities a room type includes (e.g. 1 extra bed per room) are handed over
 * when the guest checks in: each checked-in room gets an approved, ₱0 amenity row
 * per included amenity. That row is what takes the units out of stock -
 * Amenity::remainingStockFor() counts every non-rejected row until the booking is
 * completed - so they come back at check-out like any other amenity. It also lists
 * the item on the bill at ₱0. More of the same amenity bought as an add-on is a
 * separate, charged row.
 */
class IncludedAmenityService
{
    public function grant(Booking $booking, Stay $stay): void
    {
        $rooms = $booking->rooms()->wherePivot('stay_id', $stay->id)->with('roomType.assignedAmenities')->get();
        if ($rooms->isEmpty()) {
            // a booking from before rooms were tied to a stay
            $rooms = $booking->rooms()->wherePivotNull('stay_id')->with('roomType.assignedAmenities')->get();
        }
        $guestId = $booking->reservation_id ? $booking->reservation?->guest_id : $booking->guest_id;

        foreach ($rooms as $room) {
            foreach ($room->roomType?->paidAmenities() ?? [] as $amenity) {
                $owner = $booking->reservation_id ? ['reservation_id' => $booking->reservation_id] : ['booking_id' => $booking->id];
                $already = AmenityRequest::where($owner)->where('stay_id', $stay->id)->where('room_id', $room->id)
                    ->where('amenity_id', $amenity->id)->where('origin', AmenityRequest::ORIGIN_INCLUDED)->exists();
                if ($already) {
                    continue;
                }

                AmenityRequest::create([
                    'guest_id' => $guestId,
                    'reservation_id' => $booking->reservation_id,
                    'booking_id' => $booking->reservation_id ? null : $booking->id,
                    'stay_id' => $stay->id,
                    'room_id' => $room->id,
                    'room_type_id' => $room->room_type_id,
                    'amenity_id' => $amenity->id,
                    'amenity_name' => $amenity->amenity_name . ' (included)',
                    'category' => $amenity->category,
                    'quantity' => RoomType::includedQuantity($amenity),
                    'charge' => 0, // part of the room - nothing added to the bill
                    'status' => 'approved',
                    'origin' => AmenityRequest::ORIGIN_INCLUDED,
                    'note' => 'Included with the ' . ($room->roomType?->name ?? 'room') . ' room',
                    'decided_by' => auth()->id(),
                    'decided_at' => now(),
                ]);
            }
        }
    }
}
