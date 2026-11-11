<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * After "Confirm these dates" and Next, Step 1 behaves the same on the web and in the app: the short review ("Review your dates and
 * rooms": each date range with its rooms and subtotal, the total for all dates, the promos; Edit dates and rooms / Continue), then the
 * question "Proceed to Amenities?" for a booking or "Proceed to Representative and guests?" for a reservation. The browser behaviour is
 * checked in headless Chrome; here the words and the order are pinned, and the same words are pinned against the app's strings.
 */
class StepOneReviewAndProceedTest extends TestCase
{
    private function js(): string
    {
        return file_get_contents(public_path('js/portal/wizard.js'));
    }

    public function test_next_on_step_one_opens_the_review_and_only_a_yes_moves_on(): void
    {
        $js = $this->js();

        $this->assertStringContainsString('reviewStays().then(function (yes) { if (yes) go(1); });', $js);
        $this->assertStringContainsString("title: 'Review your dates and rooms'", $js);
        $this->assertStringContainsString("confirmText: 'Continue', cancelText: 'Edit dates and rooms'", $js);
        $this->assertStringContainsString("var next = mode === 'booking' && !editId ? 'Amenities' : 'Representative and guests';", $js);
        $this->assertStringContainsString("title: 'Proceed to ' + next + '?'", $js);
        $this->assertStringContainsString("confirmText: 'Yes, continue'", $js);
        // the review comes first; its Edit answer (false) stops there and the question is only asked after Continue
        $this->assertMatchesRegularExpression("/if \\(!yes\\) return false;\\s*return VS\\.confirm\\(\\{ title: 'Proceed to '/", $js);
    }

    public function test_the_review_lists_the_same_things_in_the_same_order_as_the_app(): void
    {
        $js = $this->js();
        $order = [
            "' \\u2192 '",                          // "Nov 13 → Nov 14 · 1 night" heading per date range
            "rm.qty + ' \\u00d7 ' + unitName(t)",   // "2 × Deluxe × ₱3,500.00 × 1 night"
            '<span>Subtotal</span>',
            '<span>Total for all dates</span>',
            'Promos for your rooms (verified at check-in)',
            'Estimated saving',
            'Promos are checked by the front desk at check-in. The Grand total stays whole until then.',
        ];
        $at = strpos($js, 'function reviewStays()');
        $this->assertNotFalse($at);
        foreach ($order as $needle) {
            $pos = strpos($js, $needle, $at);
            $this->assertNotFalse($pos, $needle);
            $at = $pos;
        }
        // the confirmation's summary line is "2 stays · 3 rooms · ₱7,000.00", as in the app
        $this->assertStringContainsString("VS.plural(S.stays.length, 'stay') + ' \\u00b7 ' + VS.plural(rooms, 'room') + ' \\u00b7 ' + VS.money(total)", $js);
    }

    public function test_the_app_has_the_very_same_words(): void
    {
        $strings = '';
        foreach ([base_path('../AndroidStudioProjects/VelocitySuites/app/src/main/res/values/strings.xml'), 'C:/Users/user/AndroidStudioProjects/VelocitySuites/app/src/main/res/values/strings.xml'] as $path) {
            if (is_file($path)) {
                $strings = file_get_contents($path);
                break;
            }
        }
        if ($strings === '') {
            $this->markTestSkipped('The app repository is not next to this one.');
        }

        foreach ([
            '<string name="review_stays_title">Review your dates and rooms</string>',
            '<string name="review_edit_stays">Edit dates and rooms</string>',
            '<string name="review_continue">Continue</string>',
            '<string name="proceed_to_amenities">Proceed to Amenities?</string>',
            '<string name="proceed_to_rep_guests">Proceed to Representative and guests?</string>',
            '<string name="wizard_confirm_yes">Yes, continue</string>',
            '<string name="stays_total_label">Total for all dates</string>',
            '<string name="review_stay_subtotal">Subtotal</string>',
            '<string name="promos_for_your_rooms">Promos for your rooms (verified at check-in)</string>',
            '<string name="promo_to_be_verified_note">Promos are checked by the front desk at check-in. The Grand total stays whole until then.</string>',
        ] as $line) {
            $this->assertStringContainsString($line, $strings, $line);
        }
    }
}
