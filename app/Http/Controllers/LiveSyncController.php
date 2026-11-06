<?php

namespace App\Http\Controllers;

use App\Services\StayAlerts;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Live updates for the staff pages (no websockets on shared hosting): the page asks
 * this endpoint every few seconds for a fingerprint of the data staff screens show.
 * Only when it changes does the page re-fetch itself and swap its marked parts
 * (public/js/app.js, "Live sync"). One COUNT + MAX(updated_at) per table, plus the
 * hotel's 12 NN and 2 PM: those times passing changes what the screens show without
 * any row changing, so a page left open still gets its red dots and alerts.
 */
class LiveSyncController extends Controller
{
    private const TABLES = [
        'reservations', 'bookings', 'stays', 'payments', 'billings',
        'amenity_requests', 'rooms', 'amenities', 'notifications',
    ];

    public function stamp(): JsonResponse
    {
        StayAlerts::sweepThrottled();

        $parts = [HotelClock::lastDueCheckOutDay()->toDateString(), HotelClock::lastDueCheckInDay()->toDateString()];
        foreach (self::TABLES as $table) {
            $row = DB::table($table)->selectRaw('COUNT(*) as c, MAX(updated_at) as u')->first();
            $parts[] = $table . ':' . ($row->c ?? 0) . ':' . ($row->u ?? '');
        }

        $stamp = md5(implode('|', $parts));
        // The page re-fetches itself next: its sidebar dots must not come from the short cache of the old state.
        if (Cache::get('live-sync:stamp') !== $stamp) {
            Cache::forever('live-sync:stamp', $stamp);
            Cache::forget('sidebar:has_new_checkins');
            Cache::forget('sidebar:has_new_checkouts');
        }

        return response()->json(['stamp' => $stamp])
            ->header('Cache-Control', 'no-store');
    }
}
