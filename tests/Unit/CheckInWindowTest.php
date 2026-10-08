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

    public function test_receptionists_and_admins_are_exempt_but_guests_are_not(): void
    {
        $tomorrow = ['check_in' => '2026-10-09'];
        foreach (['receptionist', 'admin'] as $role) {
            $staff = new User(['role' => $role]);
            $staff->role = $role;
            $this->assertTrue(Validator::make($tomorrow, ['check_in' => CheckInWindow::rulesFor($staff, $this->now())])->passes(), $role);
            $this->assertTrue(Validator::make(['check_in' => '2026-10-08'], ['check_in' => CheckInWindow::rulesFor($staff, $this->now())])->passes(), "$role today");
            $this->assertFalse(Validator::make(['check_in' => '2026-10-07'], ['check_in' => CheckInWindow::rulesFor($staff, $this->now())])->passes(), "$role past");
        }
        $guest = new User();
        $guest->role = 'guest';
        $this->assertFalse(Validator::make($tomorrow, ['check_in' => CheckInWindow::rulesFor($guest, $this->now())])->passes());
        $this->assertTrue(Validator::make(['check_in' => '2026-10-10'], ['check_in' => CheckInWindow::rulesFor($guest, $this->now())])->passes());
    }
}
