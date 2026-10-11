<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Reservation;
use App\Services\NotificationService;
use Carbon\Carbon;

/**
 * One stay, one number: once a reservation converts, the receptionist web knows it only as Booking #<bookings.id>,
 * so every guest-facing message must quote that number - never the reservation's own id.
 */
class BookingIdConsistencyTest extends ApiFlowTestCase
{
    public function test_checkin_reminder_for_a_converted_reservation_quotes_the_booking_id_not_the_reservation_id(): void
    {
        [$user, $guest] = $this->makeGuestUser('idc');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);

        // Make the two auto-increment sequences differ, exactly like production (reservation 606 -> booking 521).
        Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => Carbon::now()->addDays(3), 'check_out' => Carbon::now()->addDays(4),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0, 'status' => 'CONVERTED_TO_BOOKING',
        ]);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => Carbon::now()->addDays(3), 'check_out' => Carbon::now()->addDays(4),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0, 'status' => 'CONVERTED_TO_BOOKING',
        ]);
        $booking = Booking::create([
            'reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => Carbon::now()->addDays(3), 'check_out' => Carbon::now()->addDays(4),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0, 'booking_status' => 'CONFIRMED',
        ]);
        Booking::query()->where('id', $booking->id)->update(['id' => 521]);
        $booking = Booking::findOrFail(521);
        $this->assertNotSame($reservation->id, $booking->id);

        app(NotificationService::class)->notifyCheckinReminder($booking);

        $message = Notification::where('user_id', $user->id)->where('category', 'checkin_reminder')->value('message');
        $this->assertNotNull($message);
        $this->assertStringContainsString('Booking #521', $message);
        $this->assertStringNotContainsString("Reservation #{$reservation->id}", $message);
    }
}
