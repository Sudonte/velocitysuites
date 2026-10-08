<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use App\Support\CheckInWindow;

/**
 * The guest WEB pages follow the same shared check-in rule as the mobile API (App\Support\CheckInWindow), while
 * receptionist / admin accounts stay exempt and can still book for today or tomorrow.
 */
class GuestWebAndStaffCheckInRulesTest extends ApiFlowTestCase
{
    private function staff(string $role): User
    {
        return User::create([
            'first_name' => 'Staff', 'last_name' => ucfirst($role), 'email' => "{$role}-rule@example.test",
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_the_public_room_pages_disable_dates_before_the_earliest_check_in_and_before_check_in_plus_one(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $earliest = CheckInWindow::earliest();
        $checkOutMin = CheckInWindow::earliestCheckOut($earliest);

        foreach ([route('public.rooms.index'), route('public.rooms.show', $rt)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('name="check_in"', $html);
            $this->assertMatchesRegularExpression('/name="check_in"[^>]*min="'.$earliest.'"|min="'.$earliest.'"[^>]*name="check_in"/s', $html, $url);
            $this->assertStringContainsString('min="'.$checkOutMin.'"', $html, $url);
            // the picker keeps check-out >= check-in + 1 as the guest changes check-in
            $this->assertStringContainsString('EARLIEST', $html);
        }
    }

    public function test_the_default_dates_on_the_public_pages_are_the_earliest_stay_even_for_a_stale_link(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $stale = $this->get(route('public.rooms.show', ['roomType' => $rt, 'check_in' => now()->toDateString(), 'check_out' => now()->toDateString()]))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.CheckInWindow::earliest().'"', $stale, 'a stale check-in is lifted to the earliest allowed date');
        $this->assertStringContainsString('value="'.CheckInWindow::earliestCheckOut(CheckInWindow::earliest()).'"', $stale);
    }

    public function test_the_guest_reservation_form_rejects_a_check_in_inside_the_lead_time_and_accepts_the_earliest(): void
    {
        [$user] = $this->makeGuestUser('WebGuest');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $earliest = CheckInWindow::earliest();

        $bad = $this->actingAs($user)->get(route('guest.reservations.create', [
            'room_type_id' => $rt->id, 'check_in' => now('Asia/Manila')->addDay()->toDateString(), 'check_out' => now('Asia/Manila')->addDays(3)->toDateString(),
        ]));
        $bad->assertRedirect(route('public.rooms.index'));

        $same = $this->actingAs($user)->get(route('guest.reservations.create', [
            'room_type_id' => $rt->id, 'check_in' => $earliest, 'check_out' => $earliest,
        ]));
        $same->assertRedirect(route('public.rooms.index'));

        $ok = $this->actingAs($user)->get(route('guest.reservations.create', [
            'room_type_id' => $rt->id, 'check_in' => $earliest, 'check_out' => CheckInWindow::earliestCheckOut($earliest),
        ]));
        $ok->assertOk();
    }

    public function test_a_receptionist_can_still_create_a_booking_for_today_and_for_tomorrow(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 4);
        $receptionist = $this->staff('receptionist');

        foreach ([0, 1] as $offset) {
            $in = now('Asia/Manila')->addDays($offset);
            $response = $this->actingAs($receptionist)->post(route('receptionist.bookings.store'), [
                'guest_first_name' => 'Walk', 'guest_last_name' => 'In'.$offset,
                'room_type_id' => $rt->id, 'rooms_requested' => 1,
                'check_in' => $in->toDateString(), 'check_out' => $in->copy()->addDay()->toDateString(),
                'adults' => 1, 'children' => 0,
            ]);
            $response->assertSessionHasNoErrors();
        }
        $this->assertSame(2, Booking::whereNull('guest_id')->count(), 'both receptionist bookings were created');
    }

    public function test_the_guest_mobile_api_endpoints_are_not_reachable_with_the_staff_exemption(): void
    {
        // the exemption lives in rulesFor(); the API controllers always use the strict guest rule()
        $source = file_get_contents(base_path('app/Http/Controllers/Api/BookingController.php')).file_get_contents(base_path('app/Http/Controllers/Api/ReservationController.php'));
        $this->assertStringNotContainsString('rulesFor', $source);
        $this->assertStringContainsString('CheckInWindow::rules()', $source);
    }
}
