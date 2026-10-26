<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\User;

/**
 * Staff pages poll a fingerprint of the data they show and refresh their marked
 * parts only when it changes (LiveSyncController + public/js/app.js "Live sync").
 */
class LiveSyncTest extends ApiFlowTestCase
{
    private function user(string $role): User
    {
        return User::create(['first_name' => 'T', 'last_name' => ucfirst($role), 'email' => "{$role}@livesync.test", 'role' => $role]);
    }

    public function test_the_fingerprint_changes_when_staff_data_changes(): void
    {
        $desk = $this->user('receptionist');
        $first = $this->actingAs($desk)->getJson(route('staff.live-stamp'))->assertOk()->json('stamp');
        $this->assertSame($first, $this->actingAs($desk)->getJson(route('staff.live-stamp'))->json('stamp')); // nothing changed

        $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1); // a new room
        $second = $this->actingAs($desk)->getJson(route('staff.live-stamp'))->json('stamp');
        $this->assertNotSame($first, $second);

        Amenity::create(['amenity_name' => 'Extra Bed', 'quantity' => 5, 'charge' => 500, 'status' => 'active']);
        $this->assertNotSame($second, $this->actingAs($desk)->getJson(route('staff.live-stamp'))->json('stamp'));
    }

    public function test_only_staff_can_poll_it_and_only_staff_pages_turn_it_on(): void
    {
        foreach (['admin', 'manager', 'receptionist'] as $role) {
            $this->actingAs($this->user($role))->getJson(route('staff.live-stamp'))->assertOk()->assertJsonStructure(['stamp']);
        }
        $guest = $this->user('guest');
        $this->actingAs($guest)->getJson(route('staff.live-stamp'))->assertForbidden(); // role middleware: JSON callers get 403
        auth()->logout();
        $this->getJson(route('staff.live-stamp'))->assertUnauthorized();
    }
}
