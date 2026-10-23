<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Room lines, assigned rooms and amenity rows say which stay of their transaction they belong to (stay_id). A row created without one
 * (older callers, the website, the receptionist forms - all one-stay transactions) belongs to the transaction's first stay, so the
 * data is stay-tagged whatever created it. Rows that name a stay (a multi-stay booking) keep it.
 */
trait BelongsToStay
{
    public static function bootBelongsToStay(): void
    {
        static::creating(function ($row) {
            if ($row->stay_id !== null) {
                return;
            }
            $row->stay_id = static::firstStayIdFor($row);
        });
    }

    private static function firstStayIdFor($row): ?int
    {
        $bookingId = $row->booking_id ?? null;
        if (! $bookingId && ! empty($row->reservation_id)) {
            // a converted reservation's rows are the booking's from then on
            $bookingId = DB::table('bookings')->where('reservation_id', $row->reservation_id)->value('id');
        }
        $query = DB::table('stays');
        if ($bookingId) {
            $query->where('booking_id', $bookingId);
        } elseif (! empty($row->reservation_id)) {
            $query->where('reservation_id', $row->reservation_id);
        } else {
            return null;
        }

        return $query->orderBy('position')->orderBy('id')->value('id');
    }
}
