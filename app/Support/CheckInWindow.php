<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The one place that defines which check-in dates a guest may submit: the
 * hotel's local "today" (Asia/Manila - never the server's UTC clock, which is
 * a different calendar date for 8 hours of every day) through
 * MAX_DAYS_AHEAD days after it. Mirrors the Android app's CheckInWindow so the
 * phone and the server agree on what "today" is.
 */
class CheckInWindow
{
    public const TIMEZONE = 'Asia/Manila';

    public const MAX_DAYS_AHEAD = 2;

    public static function today(?Carbon $now = null): Carbon
    {
        return ($now ? $now->copy() : Carbon::now())->setTimezone(self::TIMEZONE)->startOfDay();
    }

    public static function earliest(?Carbon $now = null): string
    {
        return self::today($now)->toDateString();
    }

    public static function latest(?Carbon $now = null): string
    {
        return self::today($now)->addDays(self::MAX_DAYS_AHEAD)->toDateString();
    }

    /** Laravel validation rules for a check_in field, evaluated against the hotel's date. */
    public static function rules(?Carbon $now = null): array
    {
        return ['required', 'date', 'after_or_equal:'.self::earliest($now), 'before_or_equal:'.self::latest($now)];
    }

    public static function messages(): array
    {
        return [
            'check_in.after_or_equal' => 'Check-in must be today or within the next '.self::MAX_DAYS_AHEAD.' days.',
            'check_in.before_or_equal' => 'Check-in must be today or within the next '.self::MAX_DAYS_AHEAD.' days.',
        ];
    }
}
