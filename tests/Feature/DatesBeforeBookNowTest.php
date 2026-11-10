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
        $this->assertMatchesRegularExpression('/function go\(intent\)\s*\{\s*var t = totals\(\); if \(!t\.rooms\) return;\s*if \(!datesOk\(\)\) \{ needDates\(\); return; \}/', $js);
        // the message sits in the date row and the page scrolls to it
        $this->assertStringContainsString("row.scrollIntoView", $js);
        $this->assertStringContainsString("data-go=\"book\">Book now", $js);
        $this->assertStringContainsString("data-go=\"reserve\">Reserve", $js);
    }

    public function test_a_changed_date_is_saved_as_chosen_at_once(): void
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

    public function test_room_details_without_chosen_dates_opens_the_rooms_page_on_its_date_picker(): void
    {
        $js = file_get_contents(public_path('js/portal/rooms.js'));

        $this->assertStringContainsString("qs.get('chosen') === '1'", $js);
        $this->assertStringContainsString("'/guest/rooms?dates_first=1&room='", $js);
        // the cards carry the dates as chosen (pre-filled dates count)
        $this->assertStringContainsString("if (st.chosen) p.set('chosen', '1');", $js);
        // the Rooms page asks for the dates, with the room selected
        $this->assertStringContainsString("arrive.get('dates_first')", $js);
        $this->assertStringContainsString("sel.preset(arrive.get('room'))", $js);
        $this->assertStringContainsString('if (!sel.datesOk()) sel.needDates();', $js);
    }

    public function test_the_wizard_without_dates_rooms_or_a_draft_goes_to_the_rooms_page_first(): void
    {
        $wiz = file_get_contents(public_path('js/portal/wizard.js'));

        $this->assertStringContainsString("window.location.replace('/guest/rooms?dates_first=1&mode=' + (mode === 'reservation' ? 'reserve' : 'book') + (draftReset ? '&reset=1' : ''))", $wiz);
        $this->assertStringContainsString("!editId && !pre.get('sel') && !pre.get('room')", $wiz);
        // the New booking / New reservation buttons and the dashboard shortcuts all land on the wizard routes, which apply it
        $this->assertStringContainsString('/guest/book/new', file_get_contents(public_path('js/portal/dashboard.js')));
        $this->assertStringContainsString('data-book', file_get_contents(resource_path('views/portal/stays.blade.php')));
    }

    public function test_new_reservation_and_new_booking_open_the_rooms_page_in_their_mode(): void
    {
        $js = file_get_contents(public_path('js/portal/rooms.js'));

        $this->assertStringContainsString("get('mode')", $js);
        $this->assertStringContainsString("You’re making a reservation (pay later)", $js);
        $this->assertStringContainsString("You’re making a booking (pay now)", $js);
        $this->assertStringContainsString("Switch to booking", $js);
        $this->assertStringContainsString("Switch to reservation", $js);
        // the bar offers only the chosen action, under the "Selected" labels
        $this->assertStringContainsString("book.hidden = mode === 'reserve'; reserve.hidden = mode === 'book';", $js);
        $this->assertStringContainsString("mode ? 'Book Selected' : 'Book now'", $js);
        $this->assertStringContainsString("mode ? 'Reserve Selected' : 'Reserve'", $js);
        // the dates rule is the same function, and Room Details keeps both actions (no mode)
        $this->assertStringContainsString('if (!datesOk()) { needDates(); return; }', $js);
        $this->assertStringNotContainsString("dates_first=1&room=' + encodeURIComponent(rt.id) + '&mode", $js);
    }
}
