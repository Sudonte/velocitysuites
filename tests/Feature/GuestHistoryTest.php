<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\User;

/**
 * Guest History (Receptionist and Manager, read-only): account holders with
 * their reservations and stays, records without an account listed apart,
 * test accounts hidden, and no access for guests.
 */
class GuestHistoryTest extends ApiFlowTestCase
{
    private function staff(string $role): User
    {
        return User::create([
            'first_name' => 'S', 'last_name' => ucfirst($role), 'email' => "{$role}-history@example.test",
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_history_lists_guests_and_shows_their_stays(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$user, $guest] = $this->makeGuestUser('hist');
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now()->addDays(5), 'check_out' => now()->addDays(7),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'status' => Reservation::STATUS_CONVERTED,
        ]);
        Booking::create([
            'reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now()->addDays(5), 'check_out' => now()->addDays(7),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_ACTIVE,
        ]);
        Reservation::create([
            'guest_first_name' => 'Walk', 'guest_last_name' => 'Innerson', 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now()->addDays(3), 'check_out' => now()->addDays(4),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'status' => Reservation::STATUS_AWAITING_CASH,
        ]);

        foreach (['receptionist', 'manager'] as $role) {
            $this->actingAs($this->staff($role));

            $this->get(route('guest-history.index'))->assertOk()->assertSee($user->full_name)->assertDontSee('Innerson');
            $this->get(route('guest-history.index', ['view' => 'unlinked']))->assertOk()->assertSee('Innerson');
            $this->get(route('guest-history.show', $guest))->assertOk()
                ->assertViewHas('stays', fn ($stays) => $stays->count() === 1)
                ->assertViewHas('reservations', fn ($r) => $r->count() === 1);
        }
    }

    public function test_test_accounts_are_hidden_and_guests_cannot_open_it(): void
    {
        [$user] = $this->makeGuestUser('qa');
        $user->forceFill(['is_test_account' => true])->save();

        $this->actingAs($this->staff('receptionist'))->get(route('guest-history.index'))->assertOk()->assertDontSee($user->email);

        [$guestUser] = $this->makeGuestUser('nosy');
        $this->actingAs($guestUser)->get(route('guest-history.index'))->assertRedirect();
    }
}
