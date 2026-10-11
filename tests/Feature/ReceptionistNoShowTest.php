<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;

/**
 * No-shows and cancellations are set by hand by a receptionist: Mark as
 * No-Show (on/after check-in day), Undo No-Show, Cancel - each records who
 * did it - and archived bookings can be restored (nothing is deleted).
 */
class ReceptionistNoShowTest extends ApiFlowTestCase
{
    private function receptionist(): User
    {
        return User::create([
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-noshow@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function confirmed(string $checkIn): Booking
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);

        return Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Walk', 'guest_last_name' => 'In',
            'check_in' => $checkIn, 'check_out' => Carbon::parse($checkIn)->addDays(2)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'verified_at' => now(),
        ]);
    }

    public function test_mark_no_show_records_who_and_undo_restores(): void
    {
        $desk = $this->receptionist();
        $booking = $this->confirmed(today()->toDateString());

        $this->actingAs($desk)->put(route('receptionist.bookings.no-show', $booking), ['reason' => 'Never arrived'])->assertRedirect();
        $booking->refresh();
        $this->assertSame(Booking::STATUS_NO_SHOW, $booking->booking_status);
        $this->assertSame($desk->id, $booking->status_changed_by);
        $this->assertNotNull($booking->no_show_at);
        $this->assertSame('Never arrived', $booking->rejection_reason);
        $this->assertTrue(Notification::where('title', 'Booking No-Show')->exists());

        $this->put(route('receptionist.bookings.undo-no-show', $booking))->assertRedirect();
        $booking->refresh();
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->booking_status);
        $this->assertNull($booking->no_show_at);
    }

    public function test_no_show_not_allowed_before_check_in_day(): void
    {
        $booking = $this->confirmed(today()->addDays(3)->toDateString());

        $this->actingAs($this->receptionist())->put(route('receptionist.bookings.no-show', $booking))->assertStatus(422);
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->fresh()->booking_status);
    }

    public function test_cancel_then_archive_and_restore(): void
    {
        $desk = $this->receptionist();
        $booking = $this->confirmed(today()->addDay()->toDateString());

        $this->actingAs($desk)->put(route('receptionist.bookings.cancel', $booking))->assertRedirect();
        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->booking_status);
        $this->assertSame($desk->id, $booking->status_changed_by);
        $this->assertNotNull($booking->cancelled_at);

        $this->put(route('receptionist.bookings.archive', $booking))->assertRedirect();
        $this->assertNotNull($booking->fresh()->hidden_at);
        $this->put(route('receptionist.bookings.restore', $booking))->assertRedirect();
        $this->assertNull($booking->fresh()->hidden_at);
        $this->assertNotNull(Booking::find($booking->id), 'nothing is deleted');
    }
}
