<?php

namespace App\Support;

use App\Models\User;
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
 * Receptionist and admin accounts book on a guest's behalf, often for today or tomorrow, so they are exempt
 * (see rulesFor()); the receptionist forms also keep their own, separate rules.
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

    /**
     * check_in rules for whoever is making the request: guests get rules(); a receptionist or admin booking on a
     * guest's behalf is only required to give a real date that is not in the past.
     */
    public static function rulesFor(?User $user, ?Carbon $now = null): array
    {
        if ($user && in_array($user->role, ['receptionist', 'admin'], true)) {
            return ['required', 'date', 'after_or_equal:'.self::today($now)->toDateString()];
        }

        return self::rules($now);
    }

    public static function messages(): array
    {
        $text = 'Check-in must be at least '.self::MIN_DAYS_AHEAD.' days from today.';

        return [
            'check_in.after_or_equal' => $text,
            'check_out.after' => 'Check-out must be at least 1 day after check-in.',
        ];
    }
}
