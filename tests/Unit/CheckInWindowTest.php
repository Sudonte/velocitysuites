<?php

namespace Tests\Unit;

use App\Support\CheckInWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CheckInWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_window_is_today_through_two_days_ahead(): void
    {
        $now = Carbon::parse('2026-10-08 10:00:00', 'Asia/Manila');
        $this->assertSame('2026-10-08', CheckInWindow::earliest($now));
        $this->assertSame('2026-10-10', CheckInWindow::latest($now));
    }

    public function test_today_uses_manila_date_not_utc_date(): void
    {
        // 2026-10-08 20:00 UTC is already 2026-10-09 04:00 in Manila.
        $now = Carbon::parse('2026-10-08 20:00:00', 'UTC');
        $this->assertSame('2026-10-09', CheckInWindow::earliest($now));
        $this->assertSame('2026-10-11', CheckInWindow::latest($now));

        // 2026-10-08 15:59 UTC is still 2026-10-08 23:59 in Manila.
        $now = Carbon::parse('2026-10-08 15:59:59', 'UTC');
        $this->assertSame('2026-10-08', CheckInWindow::earliest($now));
    }

    /** @dataProvider edgeDates */
    public function test_rules_accept_and_reject_edge_dates(string $checkIn, bool $valid): void
    {
        $now = Carbon::parse('2026-10-08 09:00:00', 'Asia/Manila');
        $v = Validator::make(['check_in' => $checkIn], ['check_in' => CheckInWindow::rules($now)]);
        $this->assertSame($valid, $v->passes(), $checkIn);
    }

    public static function edgeDates(): array
    {
        return [
            'yesterday' => ['2026-10-07', false],
            'today' => ['2026-10-08', true],
            'tomorrow' => ['2026-10-09', true],
            'day after tomorrow' => ['2026-10-10', true],
            'three days out' => ['2026-10-11', false],
            'far future' => ['2027-01-01', false],
        ];
    }
}
