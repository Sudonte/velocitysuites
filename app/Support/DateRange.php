<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Resolves a dashboard/report date-range filter from the request:
 * ?period=daily|weekly|monthly|custom (default 'monthly', matching
 * Manager\ReportController's existing "current month" default) plus, for
 * period=custom, ?from=&to= (falls back to the current month if either is
 * missing/invalid). Borrows AdminReportController's swap-if-reversed
 * robustness for the custom case so a guest fat-fingering the two date
 * inputs still gets a sane range instead of an empty one.
 */
class DateRange
{
    /** @return array{0: Carbon, 1: Carbon, 2: string} [$from, $to, $period] */
    public static function resolve(Request $request): array
    {
        $period = $request->get('period', 'monthly');
        if (! in_array($period, ['daily', 'weekly', 'monthly', 'custom'], true)) {
            $period = 'monthly';
        }

        if ($period === 'custom') {
            $from = self::parseDate($request->get('from'));
            $to = self::parseDate($request->get('to'));

            if (! $from || ! $to) {
                return [self::hotelNow()->startOfMonth(), self::hotelNow()->endOfDay(), 'monthly'];
            }

            $from = $from->startOfDay();
            $to = $to->endOfDay();

            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return [$from, $to, 'custom'];
        }

        return match ($period) {
            'daily' => [self::hotelNow()->startOfDay(), self::hotelNow()->endOfDay(), 'daily'],
            'weekly' => [self::hotelNow()->startOfWeek(), self::hotelNow()->endOfDay(), 'weekly'],
            default => [self::hotelNow()->startOfMonth(), self::hotelNow()->endOfDay(), 'monthly'],
        };
    }

    /** The hotel's date as a plain date - the same form a custom range's picked dates take. */
    private static function hotelNow(): Carbon
    {
        return \App\Support\CheckInWindow::date();
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
