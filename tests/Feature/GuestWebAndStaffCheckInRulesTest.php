<?php

namespace Tests\Feature;

use App\Support\CheckInWindow;

/**
 * The guest WEB pages follow the same shared check-in rule as the mobile API (App\Support\CheckInWindow). Staff are
 * not exempt any more - see ReceptionistAdvanceCheckInTest.
 */
class GuestWebAndStaffCheckInRulesTest extends ApiFlowTestCase
{
    public function test_the_public_room_pages_ask_the_api_for_the_dates_and_keep_the_earliest_check_in_in_the_page_script(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);

        foreach ([route('public.rooms.index'), route('public.rooms.show', $rt)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('js/portal/rooms.js', $html, $url);
        }
        // the rooms page's script clamps the picker to today + 2 days (hotel time), exactly the rule the API enforces
        $script = file_get_contents(public_path('js/portal/rooms.js'));
        $this->assertStringContainsString('VS.addDays(today, 2)', $script);
    }

    public function test_the_guest_booking_pages_start_from_the_earliest_check_in_and_the_api_refuses_an_earlier_one(): void
    {
        [$user] = $this->makeGuestUser('WebGuest');
        $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);

        $this->actingAs($user)->get(route('guest.book.new'))->assertOk()->assertSee('js/portal/wizard.js', false);
        $this->actingAs($user)->get(route('guest.reserve.new'))->assertOk()->assertSee('js/portal/wizard.js', false);
        $wizard = file_get_contents(public_path('js/portal/wizard.js'));
        $this->assertStringContainsString('VS.addDays(VS.today(), 2)', $wizard);
        // the shared rule every creation goes through (the web pages call the same endpoints as the app)
        $this->assertStringContainsString('CheckInWindow::rules()', file_get_contents(base_path('app/Http/Controllers/Api/ReservationController.php')));
    }

    public function test_the_guest_mobile_api_endpoints_are_not_reachable_with_the_staff_exemption(): void
    {
        // the API controllers use the one shared rule()
        $source = file_get_contents(base_path('app/Http/Controllers/Api/BookingController.php')).file_get_contents(base_path('app/Http/Controllers/Api/ReservationController.php'));
        $this->assertStringNotContainsString('rulesFor', $source);
        $this->assertStringContainsString('CheckInWindow::rules()', $source);
    }
}
