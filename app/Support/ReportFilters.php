<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The one filter set shared by the Admin and Manager reports (screen and
 * PDF): ?period=daily|weekly|monthly|custom (default daily = Today) with
 * ?from=&to= for custom, an optional ?room_type_id=, and an optional
 * ?payment_method=cash|gcash (Admin revenue only).
 */
class ReportFilters
{
    public const QUERY_KEYS = ['period', 'from', 'to', 'room_type_id', 'payment_method'];

    /**
     * Invalid input never redirects (a GET report page bounced "back" can loop
     * to the same bad URL); it falls back to Today and reports the messages
     * in 'errors' for the page to show.
     *
     * @return array{from: Carbon, to: Carbon, period: string, room_type_id: ?int, payment_method: ?string, errors: array<string>}
     */
    public static function resolve(Request $request): array
    {
        $validator = Validator::make($request->only(self::QUERY_KEYS), [
            'period' => ['nullable', 'in:daily,weekly,monthly,custom'],
            'from' => ['nullable', 'required_if:period,custom', 'date'],
            'to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:from'],
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
            'payment_method' => ['nullable', 'in:cash,gcash'],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'from.required_if' => 'Choose a start date for a custom range.',
            'to.required_if' => 'Choose an end date for a custom range.',
        ]);

        $errors = $validator->errors()->all();
        $input = $validator->fails() ? [] : $validator->validated();
        $periodRequest = new Request([
            'period' => $input['period'] ?? 'daily',
            'from' => $input['from'] ?? null,
            'to' => $input['to'] ?? null,
        ]);
        [$from, $to, $period] = DateRange::resolve($periodRequest);

        return [
            'from' => $from,
            'to' => $to,
            'period' => $period,
            'room_type_id' => isset($input['room_type_id']) ? (int) $input['room_type_id'] : null,
            'payment_method' => $input['payment_method'] ?? null,
            'errors' => $errors,
        ];
    }

    /** Stable cache-key fragment for a resolved filter set. */
    public static function cacheKey(array $filters): string
    {
        return implode(':', [
            $filters['from']->toDateString(),
            $filters['to']->toDateString(),
            $filters['room_type_id'] ?? 'all',
            $filters['payment_method'] ?? 'all',
        ]);
    }
}
