<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the new guest-facing "Delete Permanently" feature
 * (Api\BookingController::destroy()/Api\ReservationController::destroy()).
 *
 * payments.booking_id and payments.reservation_id are both ON DELETE
 * RESTRICT at the database level - a deliberate existing guard against
 * silently losing a financial record when its parent transaction is
 * removed - and Payment has no guest_id column of its own, so a payment
 * row can never stay independently traceable if its FKs were merely
 * nulled out instead. Rather than either (a) hard-deleting payment/
 * billing data along with the rest of the transaction, or (b) blocking
 * permanent deletion of every paid transaction outright (which would
 * make the guest-facing Completed/Cancelled Booking delete feature this
 * table exists for almost entirely unusable), every payment/billing
 * record belonging to a permanently-deleted transaction is snapshotted
 * here first, then the live payments/billing rows are removed so the
 * RESTRICT constraint no longer blocks deleting the booking/reservation
 * itself. This table is never read by any guest-facing endpoint - it
 * exists purely so hotel staff/administrators retain a full audit trail
 * (amount, method, GCash reference, verification status, and the
 * original receipt file path/URL, which is left in place in storage -
 * not deleted - so this snapshot's reference to it stays valid) of what
 * a deleted transaction actually paid, without that record blocking or
 * surviving as an orphaned, unattributable row in the live payments table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_deletion_archives', function (Blueprint $table) {
            $table->id();
            // nullOnDelete (not cascade) - this audit trail must outlive the
            // guest account it originated from if that account is ever
            // separately removed later; deliberately not a hard requirement
            // the guest_id must still resolve to a live Guest row.
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            // Deliberately plain, unconstrained integers, not foreign keys -
            // the whole point of this table is that the original
            // reservations/bookings rows no longer exist by the time this
            // row is read back.
            $table->unsignedBigInteger('original_reservation_id')->nullable();
            $table->unsignedBigInteger('original_booking_id')->nullable();
            $table->string('display_reference')->nullable();
            $table->string('transaction_status')->nullable();
            $table->decimal('total_amount', 10, 2)->nullable();
            // Full JSON snapshot: reservation/booking/billing raw attributes,
            // every payment row, and every room/amenity line item - see
            // TransactionArchiveService::archiveAndPurgeFinancials().
            $table->json('snapshot');
            $table->timestamp('guest_deleted_at');
            $table->timestamps();

            $table->index('original_reservation_id');
            $table->index('original_booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_deletion_archives');
    }
};
