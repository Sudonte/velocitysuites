<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gives every booking and reservation that has no stay row yet exactly one stay (its own dates) and points its room lines, rooms and
 * amenity rows at it. Idempotent: a transaction that already has a stay is left alone, so it is safe to run again - which is exactly what
 * is done right after the code that creates stays goes live, to catch anything the OLD code created between the migration and the deploy.
 * (The application also copes with a transaction without a stay row on its own: it reads its own dates as one stay.)
 */
final class StaysBackfill
{
    /** @return array{bookings: int, reservations: int} how many transactions got a stay now */
    public static function run(): array
    {
        $made = ['bookings' => 0, 'reservations' => 0];

        DB::table('bookings')->orderBy('id')->chunkById(200, function ($bookings) use (&$made) {
            foreach ($bookings as $b) {
                if (DB::table('stays')->where('booking_id', $b->id)->exists()) {
                    continue;
                }
                $stayId = DB::table('stays')->insertGetId([
                    'booking_id' => $b->id, 'position' => 1, 'check_in' => $b->check_in, 'check_out' => $b->check_out,
                    'checked_in_at' => $b->checked_in_at ?? null, 'checked_out_at' => $b->checked_out_at ?? null,
                    'created_at' => $b->created_at ?? now(), 'updated_at' => now(),
                ]);
                $made['bookings']++;
                DB::table('booking_room_lines')->where('booking_id', $b->id)->whereNull('stay_id')->update(['stay_id' => $stayId]);
                DB::table('booking_rooms')->where('booking_id', $b->id)->whereNull('stay_id')->update(['stay_id' => $stayId]);
                DB::table('amenity_requests')->where('booking_id', $b->id)->whereNull('stay_id')->update(['stay_id' => $stayId]);
                if ($b->reservation_id) {
                    DB::table('amenity_requests')->where('reservation_id', $b->reservation_id)->whereNull('booking_id')->whereNull('stay_id')->update(['stay_id' => $stayId]);
                }
            }
        });

        DB::table('reservations')->orderBy('id')->chunkById(200, function ($reservations) use (&$made) {
            foreach ($reservations as $r) {
                if (DB::table('stays')->where('reservation_id', $r->id)->exists()) {
                    continue;
                }
                $stayId = DB::table('stays')->insertGetId([
                    'reservation_id' => $r->id, 'position' => 1, 'check_in' => $r->check_in, 'check_out' => $r->check_out,
                    'created_at' => $r->created_at ?? now(), 'updated_at' => now(),
                ]);
                $made['reservations']++;
                DB::table('reservation_room_lines')->where('reservation_id', $r->id)->whereNull('stay_id')->update(['stay_id' => $stayId]);
                DB::table('reservation_amenities')->where('reservation_id', $r->id)->whereNull('stay_id')->update(['stay_id' => $stayId]);
                DB::table('amenity_requests')->where('reservation_id', $r->id)->whereNull('stay_id')->update(['stay_id' => $stayId]);
            }
        });

        // a converted reservation's rows that were written before its booking got a stay
        DB::table('amenity_requests')->whereNull('stay_id')->whereNotNull('booking_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $stay = DB::table('stays')->where('booking_id', $row->booking_id)->orderBy('position')->value('id');
                if ($stay) {
                    DB::table('amenity_requests')->where('id', $row->id)->update(['stay_id' => $stay]);
                }
            }
        });

        return $made;
    }
}
