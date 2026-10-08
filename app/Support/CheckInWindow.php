<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The one place that defines the guest check-in / check-out rule, shared by the mobile API, the guest web
 * pages and the Android app's own mirror (CheckInWindow.java):
 *
 *   - the earliest check-in is MIN_DAYS_AHEAD (2) days from today, where "today" is the hotel's local date
 *     (Asia/Manila - never the server's UTC clock);
 *   - there is no upper limit on check-in;
 *   - check-out must be at least 1 day after check-in.
 *
 * The rule applies to everyone who creates a booking or reservation or changes its dates - guests (web and mobile
 * API) and receptionist front-desk / walk-in forms alike; there is no staff exemption. It does NOT limit the
 * receptionist's Check In action for a guest arriving on an existing booking's date.
 */
class CheckInWindow
{
    public const TIMEZONE = 'Asia/Manila';

    public const MIN_DAYS_AHEAD = 2;

    public static function today(?Carbon $now = null): Carbon
    {
        return ($now ? $now->copy() : Carbon::now())->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /** Earliest allowed check-in, Y-m-d. */
    public static function earliest(?Carbon $now = null): string
    {
        return self::today($now)->addDays(self::MIN_DAYS_AHEAD)->toDateString();
    }

    /** Earliest check-out for a given check-in date (check-in + 1 day), Y-m-d. */
    public static function earliestCheckOut(string $checkIn): string
    {
        return Carbon::parse($checkIn)->startOfDay()->addDay()->toDateString();
    }

    /** Validation rules for a guest's check_in field. */
    public static function rules(?Carbon $now = null): array
    {
        return ['required', 'date', 'after_or_equal:'.self::earliest($now)];
    }

    /** Validation rules for check_out: at least one day after check_in. */
    public static function checkOutRules(): array
    {
        return ['required', 'date', 'after:check_in'];
    }

    /** "Sat, Oct 10, 2026" - the earliest check-in spelled out for messages and notes. */
    public static function earliestLabel(?Carbon $now = null): string
    {
        return Carbon::parse(self::earliest($now))->format('D, M j, Y');
    }

    /** The note shown next to every check-in date field. */
    public static function notice(?Carbon $now = null): string
    {
        return 'Check-in must be booked at least '.self::MIN_DAYS_AHEAD.' days ('.(self::MIN_DAYS_AHEAD * 24)
            .' hours) in advance. Earliest available check-in: '.self::earliestLabel($now).'.';
    }

    /** Validation messages for the guest endpoints (web + mobile API) - wording unchanged. */
    public static function messages(): array
    {
        $text = 'Check-in must be at least '.self::MIN_DAYS_AHEAD.' days from today.';

        return [
            'check_in.after_or_equal' => $text,
            'check_out.after' => 'Check-out must be at least 1 day after check-in.',
        ];
    }

    /** Validation messages for the receptionist forms: the full note, with the computed earliest date. */
    public static function staffMessages(?Carbon $now = null): array
    {
        return [
            'check_in.after_or_equal' => self::notice($now),
            'check_out.after' => 'Check-out must be at least 1 day after check-in.',
        ];
    }
}
