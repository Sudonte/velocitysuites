<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transaction records are permanent proof for the guest AND the hotel's accounting. Several foreign keys were
 * ON DELETE CASCADE, so removing one parent row (a guest, a room, a reservation, a booking) silently erased the
 * reservations / bookings / billings / payments below it. They become RESTRICT: a delete that would take transaction
 * history with it is refused by the database instead.
 *
 *   reservations.guest_id   -> guests        (was CASCADE)
 *   reservations.room_id    -> rooms         (was CASCADE)
 *   bookings.reservation_id -> reservations  (was CASCADE)
 *   billings.booking_id     -> bookings      (was CASCADE)
 *   payments.billing_id     -> billings      (was CASCADE)
 *
 * Children that only describe their parent (room lines, amenity lines, booking_rooms, additional charges) keep
 * CASCADE - they can only go if their parent goes, and the parent can no longer go.
 * MySQL only: the test suite builds its own schema and sqlite can't alter foreign keys in place.
 */
return new class extends Migration
{
    private const KEYS = [
        ['reservations', 'guest_id', 'guests'],
        ['reservations', 'room_id', 'rooms'],
        ['bookings', 'reservation_id', 'reservations'],
        ['billings', 'booking_id', 'bookings'],
        ['payments', 'billing_id', 'billings'],
    ];

    public function up(): void
    {
        $this->rebuild('restrict');
    }

    public function down(): void
    {
        $this->rebuild('cascade');
    }

    private function rebuild(string $onDelete): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::KEYS as [$table, $column, $referenced]) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }

            $existing = DB::selectOne(
                'select CONSTRAINT_NAME as name from information_schema.KEY_COLUMN_USAGE
                 where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ? and REFERENCED_TABLE_NAME = ?',
                [$table, $column, $referenced]
            );

            Schema::table($table, function (Blueprint $t) use ($existing, $column, $referenced, $onDelete) {
                if ($existing) {
                    $t->dropForeign($existing->name);
                }
                $fk = $t->foreign($column)->references('id')->on($referenced);
                $onDelete === 'restrict' ? $fk->restrictOnDelete() : $fk->cascadeOnDelete();
            });
        }
    }
};
