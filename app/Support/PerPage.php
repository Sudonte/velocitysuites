<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Validated page size for paginated admin/manager tables (?per_page=10|25|50). */
class PerPage
{
    public const OPTIONS = [10, 25, 50];

    public static function resolve(Request $request, int $default = 10): int
    {
        $value = (int) $request->query('per_page', $default);

        return in_array($value, self::OPTIONS, true) ? $value : $default;
    }
}
