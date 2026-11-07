<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The ONE way the web pages, PDFs, exports and emails show a stored TIMESTAMP (created_at, payment_date, verified_at, checked_in_at ...).
 * Those are kept in UTC (config('app.timezone') stays UTC); they are converted to the hotel's own clock (Asia/Manila) here, at display time.
 *
 * Calendar DATES (a booking's check_in / check_out day, an announcement's start date ...) are not instants: they are shown as they are
 * and never go through this helper, so they can never shift.
 */
final class HotelTime
{
    public const TIMEZONE = CheckInWindow::TIMEZONE;

    /** The moment on the hotel's clock. A plain "Y-m-d H:i:s" string is read as UTC (the way the database stores it). Null stays null. */
    public static function at(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        $moment = $value instanceof CarbonInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC');

        return $moment->setTimezone(self::TIMEZONE);
    }

    /** The timestamp formatted on the hotel's clock, e.g. HotelTime::format($booking->created_at, 'F d, Y') or 'h:i A'. Null when there is no time. */
    public static function format(CarbonInterface|string|null $value, string $format = 'M d, Y h:i A'): ?string
    {
        return self::at($value)?->format($format);
    }

    /** "November 07, 2026 · 8:43 AM". */
    public static function dateTime(CarbonInterface|string|null $value): ?string
    {
        return self::format($value, 'F d, Y · g:i A');
    }

    /** The UTC instant the hotel day $date (Y-m-d) begins: filter a TIMESTAMP column with `>=` this ("from" date). */
    public static function dayStartUtc(string $date): Carbon
    {
        return CheckInWindow::dayBoundsUtc(Carbon::parse($date))[0];
    }

    /** The UTC instant the hotel day AFTER $date begins: filter a TIMESTAMP column with `<` this ("to" date, inclusive of the whole day). */
    public static function dayEndUtc(string $date): Carbon
    {
        return CheckInWindow::dayBoundsUtc(Carbon::parse($date))[1];
    }

    /** The hotel's calendar day of a timestamp as a plain date string (Y-m-d): 2026-11-06 23:30 UTC is 2026-11-07 here. */
    public static function day(CarbonInterface|string|null $value): ?string
    {
        return self::format($value, 'Y-m-d');
    }
}
