<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Found while re-checking the booking and reservation flows in the real dark theme (data-bs-theme="dark", the guest's own setting) at
 * 390, 820, 1366 and 1920 px: the green "2 rooms available" line was too dim on a dark card, and the "Your total so far" panel said
 * "Choose your dates" although the dates were already filled in (they are pre-filled now).
 */
class DarkModeAndSummaryPolishTest extends TestCase
{
    public function test_the_green_availability_text_is_lightened_in_dark_mode(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $this->assertStringContainsString('[data-bs-theme="dark"] .vs-page .text-success, [data-bs-theme="dark"] .vs-shell-main .text-success { color: #6fdc9c !important; }', $css);
    }

    public function test_the_lighter_green_reads_at_least_4_5_to_1_on_the_dark_card(): void
    {
        $lum = function (string $hex): float {
            $c = array_map(fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
            $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $ratio = fn (string $a, string $b) => (max($lum($a), $lum($b)) + 0.05) / (min($lum($a), $lum($b)) + 0.05);

        // the dark card surfaces of the portal (see portal.css: --vs-card in the dark theme) and the page behind them
        foreach (['#1b1d24', '#14161b', '#22252d'] as $surface) {
            $this->assertGreaterThanOrEqual(4.5, $ratio('#6fdc9c', $surface), $surface);
        }
    }

    public function test_the_switch_link_on_the_mode_banner_is_a_deeper_red_in_the_light_theme(): void
    {
        $css = file_get_contents(public_path('css/portal.css'));

        $this->assertStringContainsString('html:not([data-bs-theme="dark"]) #vsModeBanner a { color: #8f1820 !important; text-decoration: underline; }', $css);
    }

    public function test_the_total_panel_asks_to_confirm_the_dates_once_they_are_filled_in(): void
    {
        $js = file_get_contents(public_path('js/portal/wizard.js'));

        $this->assertStringContainsString("(S.draft && S.draft.check_in && S.draft.check_out ? 'Confirm these dates to see the total' : 'Choose your dates to see the total')", $js);
        $this->assertStringContainsString('paintSummary(); // "Choose your dates" / "Confirm these dates" follows the date fields', $js);
    }
}
