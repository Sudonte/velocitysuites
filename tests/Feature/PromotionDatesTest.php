<?php

namespace Tests\Feature;

use App\Models\Promotion;
use Carbon\Carbon;

/**
 * Promotions run once (specific dates) or every year (month/day window, no
 * year). Yearly windows may cross New Year; their start/end dates always hold
 * the current or next occurrence; expired one-time promotions are switched
 * off by catalog:refresh-dates.
 */
class PromotionDatesTest extends ApiFlowTestCase
{
    private function promo(array $attrs): Promotion
    {
        return Promotion::create(array_merge([
            'promo_name' => 'P' . uniqid(), 'promo_type' => 'amenity', 'discount_type' => 'fixed', 'discount_value' => 0,
            'status' => 'active', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02',
        ], $attrs));
    }

    public function test_yearly_window_across_new_year_and_one_time_dates(): void
    {
        $yearly = $this->promo(['is_recurring' => true, 'recur_start_md' => 1220, 'recur_end_md' => 105]);
        $once = $this->promo(['start_date' => '2026-10-01', 'end_date' => '2026-10-15']);

        $running = fn (string $day) => Promotion::runningOn(Carbon::parse($day))->pluck('id')->sort()->values()->all();

        $this->assertSame([$yearly->id], $running('2026-12-25'));
        $this->assertSame([$yearly->id], $running('2027-01-03'));
        $this->assertSame([], $running('2027-01-10'));
        $this->assertSame([$once->id], $running('2026-10-10'));
        $this->assertSame([], $running('2027-10-10'), 'one-time promotions do not repeat');
    }

    public function test_recurring_dates_roll_to_next_occurrence(): void
    {
        $p = new Promotion(['is_recurring' => true, 'recur_start_md' => 1220, 'recur_end_md' => 105]);
        $p->syncRecurringDates(Carbon::parse('2026-10-11'));
        $this->assertSame(['2026-12-20', '2027-01-05'], [$p->start_date->toDateString(), $p->end_date->toDateString()]);

        $p->syncRecurringDates(Carbon::parse('2027-01-03')); // inside the window: keep this occurrence
        $this->assertSame(['2026-12-20', '2027-01-05'], [$p->start_date->toDateString(), $p->end_date->toDateString()]);
    }

    public function test_refresh_command_switches_off_expired_one_time_promotions(): void
    {
        Carbon::setTestNow('2026-10-20 08:00:00');
        $expired = $this->promo(['start_date' => '2026-10-01', 'end_date' => '2026-10-15']);
        $current = $this->promo(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $yearly = $this->promo(['is_recurring' => true, 'recur_start_md' => 1001, 'recur_end_md' => 1015, 'start_date' => '2026-10-01', 'end_date' => '2026-10-15']);

        $this->artisan('catalog:refresh-dates')->assertSuccessful();

        $this->assertSame('inactive', $expired->fresh()->status);
        $this->assertSame('active', $current->fresh()->status);
        $this->assertSame('active', $yearly->fresh()->status);
        $this->assertSame('2027-10-01', $yearly->fresh()->start_date->toDateString());
        Carbon::setTestNow();
    }
}
