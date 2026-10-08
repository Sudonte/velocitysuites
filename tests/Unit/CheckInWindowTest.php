<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\CheckInWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CheckInWindowTest extends TestCase
{
    private const NOW = '2026-10-08 09:00:00'; // Manila

    private function now(): Carbon
    {
        return Carbon::parse(self::NOW, 'Asia/Manila');
    }

    public function test_earliest_check_in_is_two_days_from_today(): void
    {
        $this->assertSame('2026-10-10', CheckInWindow::earliest($this->now()));
    }

    public function test_today_is_the_hotels_manila_date_not_utc(): void
    {
        // 2026-10-08 20:00 UTC is already 2026-10-09 04:00 in Manila -> earliest is Oct 11.
        $this->assertSame('2026-10-11', CheckInWindow::earliest(Carbon::parse('2026-10-08 20:00:00', 'UTC')));
        // 15:59 UTC is still Oct 8 in Manila -> Oct 10.
        $this->assertSame('2026-10-10', CheckInWindow::earliest(Carbon::parse('2026-10-08 15:59:59', 'UTC')));
    }

    /** @dataProvider checkInCases */
    public function test_check_in_rule(string $checkIn, bool $valid): void
    {
        $v = Validator::make(['check_in' => $checkIn], ['check_in' => CheckInWindow::rules($this->now())]);
        $this->assertSame($valid, $v->passes(), $checkIn);
    }

    public static function checkInCases(): array
    {
        return [
            'yesterday' => ['2026-10-07', false],
            'today' => ['2026-10-08', false],
            'tomorrow' => ['2026-10-09', false],
            'today+2' => ['2026-10-10', true],
            'today+3' => ['2026-10-11', true],
            'months ahead' => ['2027-02-14', true],
            'a year ahead' => ['2027-10-08', true],
        ];
    }

    /** @dataProvider checkOutCases */
    public function test_check_out_must_be_at_least_one_day_after_check_in(string $checkIn, string $checkOut, bool $valid): void
    {
        $v = Validator::make(['check_in' => $checkIn, 'check_out' => $checkOut], ['check_out' => CheckInWindow::checkOutRules()]);
        $this->assertSame($valid, $v->passes(), "$checkIn -> $checkOut");
    }

    public static function checkOutCases(): array
    {
        return [
            'same day' => ['2026-10-10', '2026-10-10', false],
            'before check-in' => ['2026-10-10', '2026-10-09', false],
            'check-in + 1' => ['2026-10-10', '2026-10-11', true],
            'later' => ['2026-10-10', '2026-10-20', true],
            'late check-in, +1' => ['2026-10-15', '2026-10-16', true],
            'late check-in, same day' => ['2026-10-15', '2026-10-15', false],
        ];
    }

    public function test_earliest_check_out_is_the_day_after_the_chosen_check_in(): void
    {
        $this->assertSame('2026-10-11', CheckInWindow::earliestCheckOut('2026-10-10'));
        $this->assertSame('2026-10-16', CheckInWindow::earliestCheckOut('2026-10-15'));
    }

    public function test_the_same_rule_applies_to_everyone_there_is_no_staff_exemption(): void
    {
        $rules = ['check_in' => CheckInWindow::rules($this->now())];
        $this->assertFalse(Validator::make(['check_in' => '2026-10-08'], $rules)->passes(), 'today');
        $this->assertFalse(Validator::make(['check_in' => '2026-10-09'], $rules)->passes(), 'today + 1');
        $this->assertTrue(Validator::make(['check_in' => '2026-10-10'], $rules)->passes(), 'today + 2');
        $this->assertFalse(Validator::make(['check_in' => '2026-10-07'], $rules)->passes(), 'past');
        $this->assertFalse(method_exists(CheckInWindow::class, 'rulesFor'));
    }

    public function test_guest_messages_keep_their_original_wording(): void
    {
        $this->assertSame('Check-in must be at least 2 days from today.', CheckInWindow::messages()['check_in.after_or_equal']);
    }

    public function test_the_notice_names_the_computed_earliest_date(): void
    {
        $this->assertSame(
            'Check-in must be booked at least 2 days (48 hours) in advance. Earliest available check-in: Sat, Oct 10, 2026.',
            CheckInWindow::notice($this->now())
        );
        $this->assertSame(CheckInWindow::notice($this->now()), CheckInWindow::staffMessages($this->now())['check_in.after_or_equal']);
    }
}
