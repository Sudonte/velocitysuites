<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * The room gallery moves from being owned by one "representative"
     * individual Room (RoomType::getGalleryAttribute() used to borrow
     * whichever room sorted first by room_number) to being owned directly
     * by the Room Type itself - the System Administrator now manages one
     * gallery per type, not per physical room. Backfills from exactly the
     * room each type's gallery already displayed (same MIN(room_number)
     * pick getGalleryAttribute() used), so nothing visibly changes for
     * guests/receptionists/mobile at cutover; every other room's now-
     * redundant gallery images (and files) are deleted, since per-room
     * galleries are retired entirely in this change.
     */
    public function up(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->after('id')->constrained('room_types')->cascadeOnDelete();
            $table->timestamp('image_changed_at')->nullable()->after('sort_order');
        });

        // Claim the representative room's images (lowest room_number per
        // type - the exact room getGalleryAttribute() already picked) for
        // the type itself.
        DB::statement('
            UPDATE room_images ri
            JOIN rooms r ON r.id = ri.room_id
            JOIN (
                SELECT room_type_id, MIN(room_number) AS rep_number
                FROM rooms
                GROUP BY room_type_id
            ) rep ON rep.room_type_id = r.room_type_id AND rep.rep_number = r.room_number
            SET ri.room_type_id = r.room_type_id
        ');

        // Every other room's now-redundant gallery (room_type_id still
        // null after the claim above) - delete the files, then the rows.
        $orphaned = DB::table('room_images')->whereNull('room_type_id')->get(['id', 'image_path']);
        foreach ($orphaned as $image) {
            Storage::disk('public')->delete($image->image_path);
        }
        DB::table('room_images')->whereNull('room_type_id')->delete();

        Schema::table('room_images', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropColumn('room_id');
            $table->foreignId('room_type_id')->nullable(false)->change();
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->timestamp('image_changed_at')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('image_changed_at');
        });

        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('id')->constrained('rooms')->cascadeOnDelete();
        });

        // Best-effort only: repoints every remaining gallery row back onto
        // its type's representative room. Rows deleted during up() (every
        // non-representative room's gallery) cannot be restored.
        DB::statement('
            UPDATE room_images ri
            JOIN (
                SELECT room_type_id, MIN(room_number) AS rep_number
                FROM rooms
                GROUP BY room_type_id
            ) rep ON rep.room_type_id = ri.room_type_id
            JOIN rooms r ON r.room_type_id = rep.room_type_id AND r.room_number = rep.rep_number
            SET ri.room_id = r.id
        ');

        Schema::table('room_images', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
            $table->dropColumn(['room_type_id', 'image_changed_at']);
            $table->foreignId('room_id')->nullable(false)->change();
        });
    }
};
