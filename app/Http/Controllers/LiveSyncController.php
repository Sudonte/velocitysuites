<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Live updates for the staff pages (no websockets on shared hosting): the page asks
 * this endpoint every few seconds for a fingerprint of the data staff screens show.
 * Only when it changes does the page re-fetch itself and swap its marked parts
 * (public/js/app.js, "Live sync"). One COUNT + MAX(updated_at) per table.
 */
class LiveSyncController extends Controller
{
    private const TABLES = [
        'reservations', 'bookings', 'stays', 'payments', 'billings',
        'amenity_requests', 'rooms', 'amenities', 'notifications',
    ];

    public function stamp(): JsonResponse
    {
        $parts = [];
        foreach (self::TABLES as $table) {
            $row = DB::table($table)->selectRaw('COUNT(*) as c, MAX(updated_at) as u')->first();
            $parts[] = $table . ':' . ($row->c ?? 0) . ':' . ($row->u ?? '');
        }

        return response()->json(['stamp' => md5(implode('|', $parts))])
            ->header('Cache-Control', 'no-store');
    }
}
