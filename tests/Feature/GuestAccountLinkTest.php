<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\User;
use App\Support\CheckInWindow;

/**
 * Receptionist Create Booking / Create Reservation / Walk-in Check-in can be
 * linked to an existing guest account (optional): the stay gets the guest's
 * guest_id, the guest is notified, and the lookup only finds guest accounts.
 */
class GuestAccountLinkTest extends ApiFlowTestCase
{
    private function desk(): User
    {
        return User::create([
            'first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-link@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function payload(int $roomTypeId, array $extra = []): array
    {
        $in = CheckInWindow::earliest();

        return array_merge([
            'guest_first_name' => 'Typed', 'guest_last_name' => 'Name', 'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'check_in' => $in, 'check_out' => \Carbon\Carbon::parse($in)->addDay()->toDateString(), 'adults' => 1, 'children' => 0,
        ], $extra);
    }

    public function test_linked_booking_and_reservation_get_the_guest_and_notify(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        [$user, $guest] = $this->makeGuestUser('link');
        $desk = $this->desk();

        $this->actingAs($desk)->post(route('receptionist.bookings.store'), $this->payload($rt->id, ['guest_user_id' => $user->id]))->assertRedirect();
        $booking = Booking::latest('id')->first();
        $this->assertSame($guest->id, $booking->guest_id);
        $this->assertTrue(Notification::where('user_id', $user->id)->where('title', 'Booking Added')->exists());

        $this->post(route('receptionist.reservations.store'), $this->payload($rt->id, ['guest_user_id' => $user->id]))->assertRedirect();
        $this->assertSame($guest->id, Reservation::latest('id')->first()->guest_id);
        $this->assertTrue(Notification::where('user_id', $user->id)->where('title', 'Reservation Added')->exists());
    }

    public function test_no_account_picked_stays_unlinked(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 3);

        $this->actingAs($this->desk())->post(route('receptionist.bookings.store'), $this->payload($rt->id))->assertRedirect();
        $this->assertNull(Booking::latest('id')->first()->guest_id);
    }

    public function test_lookup_finds_guest_accounts_only(): void
    {
        [$user] = $this->makeGuestUser('findme');
        $desk = $this->desk();

        $found = $this->actingAs($desk)->getJson(route('receptionist.guest-accounts.search', ['q' => 'findme']))->assertOk()->json();
        $this->assertSame([$user->id], array_column($found, 'id'));
        $this->assertSame([], $this->getJson(route('receptionist.guest-accounts.search', ['q' => 'desk-link']))->json(), 'staff accounts are never offered');
    }
}
