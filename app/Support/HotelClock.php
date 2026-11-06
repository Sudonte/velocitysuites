<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The hotel's policy times, on the hotel's own clock: check-out is at 12 NN and check-in at 2 PM. A stay's check_in / check_out columns
 * hold its calendar days; these say from which moment such a day counts as due.
 */
final class HotelClock
{
    public const CHECK_OUT_HOUR = 12;

    public const CHECK_IN_HOUR = 14;

    /** The latest check-out day already past its 12 NN: today from noon on, yesterday before that. */
    public static function lastDueCheckOutDay(?Carbon $now = null): Carbon
    {
        return self::lastDueDay(self::CHECK_OUT_HOUR, $now);
    }

    /** The latest check-in day already past its 2 PM: today from 2 PM on, yesterday before that. */
    public static function lastDueCheckInDay(?Carbon $now = null): Carbon
    {
        return self::lastDueDay(self::CHECK_IN_HOUR, $now);
    }

    public static function checkOutIsDue(CarbonInterface $day, ?Carbon $now = null): bool
    {
        return $day->format('Y-m-d') <= self::lastDueCheckOutDay($now)->toDateString();
    }

    public static function checkInIsDue(CarbonInterface $day, ?Carbon $now = null): bool
    {
        return $day->format('Y-m-d') <= self::lastDueCheckInDay($now)->toDateString();
    }

    private static function lastDueDay(int $hour, ?Carbon $now): Carbon
    {
        $here = ($now ? $now->copy() : Carbon::now())->setTimezone(CheckInWindow::TIMEZONE);
        $today = CheckInWindow::date($now);

        return $here->hour >= $hour ? $today : $today->subDay();
    }
}
