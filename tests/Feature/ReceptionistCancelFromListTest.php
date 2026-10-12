<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;

/** A receptionist can cancel a confirmed booking straight from the Bookings list. */
class ReceptionistCancelFromListTest extends ApiFlowTestCase
{
    private function desk(): User
    {
        return User::create([
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-cancel-list@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_confirmed_bookings_list_offers_cancel_and_it_cancels(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Standard', 2000, 2, 3);
        $booking = Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Romar', 'guest_last_name' => 'Alden',
            'check_in' => today()->addDays(5)->toDateString(), 'check_out' => today()->addDays(7)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 1500, 'payment_stage' => 'deposit',
            'payment_status' => 'completed', 'payment_date' => now()]);
        $desk = $this->desk();

        $this->actingAs($desk)->get(route('receptionist.bookings.index', ['tab' => 'verified']))->assertOk()
            ->assertSee('btn-cancel-booking', false)
            ->assertSee('data-url="' . route('receptionist.bookings.cancel', $booking) . '"', false)
            ->assertSee('data-paid="₱1,500.00"', false)
            ->assertSee('cancelBookingModal', false);

        $this->actingAs($desk)->from(route('receptionist.bookings.index', ['tab' => 'verified']))
            ->put(route('receptionist.bookings.cancel', $booking), ['reason' => 'Guest called to cancel'])
            ->assertRedirect(route('receptionist.bookings.index', ['tab' => 'verified']))
            ->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->booking_status);
        $this->assertSame('Guest called to cancel', $booking->rejection_reason);
    }
}
