<?php

namespace Tests\Feature;

use Carbon\Carbon;

/**
 * Part 1 (same on the app): the Check-in and Check-out fields are pre-filled when a rooms page opens - check-in is today + 2 days on the
 * hotel's clock (Manila), check-out the day after - availability loads for those dates at once, and the pre-filled dates count as chosen
 * (rooms can be selected and booked without touching them). The browser behaviour is checked in headless Chrome; here the contract
 * and the server's side are pinned.
 */
class PrefilledDatesTest extends ApiFlowTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_pickers_start_on_today_plus_two_and_one_night_on_the_manila_clock(): void
    {
        $rooms = file_get_contents(public_path('js/portal/rooms.js'));
        $core = file_get_contents(public_path('js/portal/core.js'));

        $this->assertStringContainsString('var earliest = VS.addDays(VS.today(), 2);', $rooms);
        $this->assertStringContainsString("check_in: saved.check_in && saved.check_in >= earliest ? saved.check_in : earliest", $rooms);
        $this->assertStringContainsString("st.check_out = saved.check_out && saved.check_out > st.check_in ? saved.check_out : VS.addDays(st.check_in, 1);", $rooms);
        $this->assertStringContainsString('cfg.ci.value = st.check_in;', $rooms);
        $this->assertStringContainsString('cfg.co.value = st.check_out;', $rooms);
        // today is the hotel's day, not the browser's
        $this->assertStringContainsString('manilaParts(new Date())', $core);
        $this->assertStringContainsString("Asia/Manila", $core);
    }

    public function test_the_prefilled_dates_count_as_chosen_and_availability_loads_at_once(): void
    {
        $rooms = file_get_contents(public_path('js/portal/rooms.js'));

        $this->assertStringContainsString('chosen: true', $rooms);
        $this->assertStringNotContainsString('chosen: !!saved.chosen', $rooms);
        // Book now / Reserve are refused only when a date is missing or not bookable
        $this->assertStringContainsString('if (!datesOk()) { needDates(); return; }', $rooms);
        // both pages search as soon as they open: the landing's Featured rooms and the Rooms page
        $this->assertMatchesRegularExpression('/if \(datesHost\) R\.bindDates\(datesHost, sel, run\);.*?\n\s*run\(\);/s', $rooms);
        $this->assertStringContainsString("Promise.resolve(run())", $rooms);
    }

    public function test_the_server_takes_today_plus_two_as_the_earliest_check_in_on_the_manila_clock(): void
    {
        // 23:30 UTC on Nov 9 is already Nov 10 in Manila: the earliest check-in is Nov 12, and the one-night stay ends Nov 13
        Carbon::setTestNow(Carbon::parse('2026-11-09 23:30:00', 'UTC'));
        $this->assertSame('2026-11-12', (string) \App\Support\CheckInWindow::earliest());

        $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $res = $this->getJson('/api/rooms?check_in=2026-11-12&check_out=2026-11-13')->assertOk()->json();
        $this->assertSame('2026-11-12', substr((string) $res['check_in'], 0, 10));
        $this->assertSame('2026-11-13', substr((string) $res['check_out'], 0, 10));
        $types = $res['room_types']['data'] ?? $res['room_types'];
        $this->assertSame(3, (int) $types[0]['available_count'], 'availability is answered for the pre-filled dates');
    }
}
