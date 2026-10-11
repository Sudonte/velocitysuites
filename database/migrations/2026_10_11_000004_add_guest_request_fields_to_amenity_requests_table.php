<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional amenities a guest asks for AFTER booking. These need the front desk's decision, so they carry:
 *   origin           where the row came from - 'booking' (picked while booking; every existing row), 'guest_request'
 *                    (the guest asked later through the app: bills only once APPROVED) or 'staff' (added by the desk).
 *   note             the guest's optional note.
 *   rejection_reason why a rejected request was rejected (shown to the guest).
 *   decided_by/_at   who approved/rejected it and when.
 * The unit price is already frozen on every request (amenity_requests.charge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->string('origin', 20)->default('booking')->after('status');
            $table->text('note')->nullable()->after('origin');
            $table->string('rejection_reason', 500)->nullable()->after('note');
            $table->foreignId('decided_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');

            $table->index(['origin', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->dropIndex(['origin', 'status']);
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['origin', 'note', 'rejection_reason', 'decided_at']);
        });
    }
};
