<?php

namespace Tests\Feature;

use App\Support\CheckInWindow;

/**
 * The guest WEB pages follow the same shared check-in rule as the mobile API (App\Support\CheckInWindow). Staff are
 * not exempt any more - see ReceptionistAdvanceCheckInTest.
 */
class GuestWebAndStaffCheckInRulesTest extends ApiFlowTestCase
{
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

    public function test_the_guest_mobile_api_endpoints_are_not_reachable_with_the_staff_exemption(): void
    {
        // the API controllers use the one shared rule()
        $source = file_get_contents(base_path('app/Http/Controllers/Api/BookingController.php')).file_get_contents(base_path('app/Http/Controllers/Api/ReservationController.php'));
        $this->assertStringNotContainsString('rulesFor', $source);
        $this->assertStringContainsString('CheckInWindow::rules()', $source);
    }
}
