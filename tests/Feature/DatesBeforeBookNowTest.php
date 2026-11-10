<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guest web, Part 1 of the simplified booking flow (same as the app): Book now / Reserve need check-in and check-out dates the guest
 * chose, otherwise the page scrolls to the date picker with one inline message; Step 1 then opens with the dates and rooms filled in.
 * The browser behaviour is checked in headless Chrome; here the contract and the wording are pinned.
 */
class DatesBeforeBookNowTest extends TestCase
{
    public function test_book_now_and_reserve_are_blocked_until_dates_are_chosen_with_one_inline_message(): void
    {
        $js = file_get_contents(public_path('js/portal/rooms.js'));

        $this->assertStringContainsString('Choose your check-in and check-out dates first.', $js);
        $this->assertMatchesRegularExpression('/function go\(intent\)\s*\{\s*var t = totals\(\); if \(!t\.rooms\) return;\s*if \(!st\.chosen\) \{ needDates\(\); return; \}/', $js);
        // the message sits in the date row and the page scrolls to it
        $this->assertStringContainsString("row.scrollIntoView", $js);
        $this->assertStringContainsString("data-go=\"book\">Book now", $js);
        $this->assertStringContainsString("data-go=\"reserve\">Reserve", $js);
    }

    public function test_the_dates_are_marked_chosen_only_when_the_guest_picks_them(): void
    {
        $js = file_get_contents(public_path('js/portal/rooms.js'));

        $this->assertStringContainsString('sel.markChosen = function () { st.chosen = true; persist(); };', $js);
        $this->assertStringContainsString('if (sel) { sel.markChosen(); sel.setDates(ci.value, co.value); } run();', $js);
    }

    public function test_step_one_offers_add_room_and_add_another_date_after_confirming(): void
    {
        $wiz = file_get_contents(public_path('js/portal/wizard.js'));

        $this->assertStringContainsString('data-add-room', $wiz);
        $this->assertStringContainsString('>Add room</button>', $wiz);
        $this->assertStringContainsString('data-add-date>Add another date</button>', $wiz);
        $this->assertStringContainsString('Confirm these dates', $wiz);
        // carried dates are re-checked and a lowered count says so
        $this->assertStringContainsString('so your selection was lowered from', $wiz);
    }
}
