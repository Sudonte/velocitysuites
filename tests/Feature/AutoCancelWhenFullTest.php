<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Reservation;
use App\Services\ReservationWorkflowService;

/**
 * When a conversion takes the last room of a type for some dates, other
 * reservations still awaiting payment for that type and those dates are
 * cancelled automatically; ones that still fit (other dates) are untouched.
 */
class AutoCancelWhenFullTest extends ApiFlowTestCase
{
    private function reservation(int $roomTypeId, string $in, string $out): Reservation
    {
        return Reservation::create([
            'room_type_id' => $roomTypeId, 'rooms_requested' => 1, 'guest_first_name' => 'G', 'guest_last_name' => uniqid(),
            'check_in' => $in, 'check_out' => $out, 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH,
        ]);
    }

    public function test_other_reservations_for_a_now_full_type_and_dates_are_cancelled(): void
    {
        \App\Models\User::create([
            'first_name' => 'R', 'last_name' => 'Desk', 'email' => 'desk-full@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1); // one room only
        $winner = $this->reservation($rt->id, '2026-12-10', '2026-12-12');
        $clash = $this->reservation($rt->id, '2026-12-11', '2026-12-13');
        $otherDates = $this->reservation($rt->id, '2026-12-20', '2026-12-22');

        $booking = Booking::create([
            'reservation_id' => $winner->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash',
        ]);
        $winner->update(['status' => Reservation::STATUS_CONVERTED]);

        $cancelled = app(ReservationWorkflowService::class)->cancelReservationsThatNoLongerFit($winner, $booking);

        $this->assertSame(1, $cancelled);
        $this->assertSame(Reservation::STATUS_CANCELLED, $clash->fresh()->status);
        $this->assertStringContainsString('fully booked', $clash->fresh()->rejection_reason);
        $this->assertSame(Reservation::STATUS_AWAITING_CASH, $otherDates->fresh()->status);
        $this->assertTrue(Notification::where('title', 'Reservation Auto-Cancelled')->exists());
    }

    public function test_nothing_is_cancelled_while_rooms_remain(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 3); // three rooms
        $winner = $this->reservation($rt->id, '2026-12-10', '2026-12-12');
        $other = $this->reservation($rt->id, '2026-12-10', '2026-12-12');
        $booking = Booking::create([
            'reservation_id' => $winner->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash',
        ]);

        $this->assertSame(0, app(ReservationWorkflowService::class)->cancelReservationsThatNoLongerFit($winner, $booking));
        $this->assertSame(Reservation::STATUS_AWAITING_CASH, $other->fresh()->status);
    }
}
