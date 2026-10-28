<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The live database was changed by hand (2026-09) in ways no migration recorded, so a fresh install got a
 * reservations.status enum the app can't write (pending_review/... instead of the Reservation::STATUS_* values)
 * and was missing columns the app reads and writes. This brings a fresh install to the live schema. Every step
 * is guarded, so on the live database (already in this state) it changes nothing.
 */
return new class extends Migration
{
    private const STATUS_ENUM = "ENUM('AWAITING_CASH_CONFIRMATION', 'AWAITING_GCASH_PAYMENT', 'TO_BE_CONVERTED', "
        . "'CONVERTED_TO_BOOKING', 'REJECTED_RESERVATION', 'CANCELLED_RESERVATION') NOT NULL DEFAULT 'AWAITING_CASH_CONFIRMATION'";

    public function up(): void
    {
        $this->addMissing('reservations', [
            'cancelled_at' => fn (Blueprint $t) => $t->timestamp('cancelled_at')->nullable(),
            'edited_at' => fn (Blueprint $t) => $t->timestamp('edited_at')->nullable(),
            'selected_payment_percentage' => fn (Blueprint $t) => $t->decimal('selected_payment_percentage', 5, 2)->nullable(),
            'required_payment_amount' => fn (Blueprint $t) => $t->decimal('required_payment_amount', 10, 2)->nullable(),
        ]);
        $this->addMissing('bookings', [
            'idempotency_key' => fn (Blueprint $t) => $t->string('idempotency_key')->nullable()->unique(),
            'selected_payment_percentage' => fn (Blueprint $t) => $t->decimal('selected_payment_percentage', 5, 2)->nullable(),
            'required_payment_amount' => fn (Blueprint $t) => $t->decimal('required_payment_amount', 10, 2)->nullable(),
        ]);

        if (DB::getDriverName() !== 'mysql' || str_contains($this->columnType('reservations', 'status'), 'AWAITING_CASH_CONFIRMATION')) {
            return;
        }

        // Old values -> the app's: the pre-booking statuses wait on the chosen payment method (as
        // ReservationWorkflowService::initialStatus() decides), the end states map one to one.
        DB::statement('ALTER TABLE reservations MODIFY status VARCHAR(40) NOT NULL');
        DB::statement("UPDATE reservations SET status = IF(payment_method = 'gcash', 'AWAITING_GCASH_PAYMENT', 'AWAITING_CASH_CONFIRMATION')
            WHERE status IN ('pending_review', 'ready_for_booking')");
        DB::statement("UPDATE reservations SET status = 'CONVERTED_TO_BOOKING' WHERE status = 'converted'");
        DB::statement("UPDATE reservations SET status = 'REJECTED_RESERVATION' WHERE status = 'rejected'");
        DB::statement("UPDATE reservations SET status = 'CANCELLED_RESERVATION' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE reservations MODIFY status ' . self::STATUS_ENUM);
    }

    public function down(): void
    {
        // The live database has always had these - nothing to undo.
    }

    private function addMissing(string $table, array $columns): void
    {
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn($table, $name)) {
                Schema::table($table, $define);
            }
        }
    }

    private function columnType(string $table, string $column): string
    {
        return (string) DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->value('COLUMN_TYPE');
    }
};
