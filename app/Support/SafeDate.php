<?php

namespace App\Support;

use Carbon\Carbon;

/** A date typed into a URL, read safely: anything that is not a real calendar date is "no date" (never a server error). */
final class SafeDate
{
    public static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value))) {
            return null;
        }
        try {
            $date = Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }

        return $date->year >= 2000 && $date->year <= 2100 ? $date : null;
    }
}
