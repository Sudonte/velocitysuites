<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Reverses the previous cutover: the gallery moves from being owned by
     * the Room Type back to being owned by each individual Room again, so
     * every physical room gets its own 4-5 photo gallery. On top of that,
     * a new merged/mixed gallery view (RoomType::mergedGalleryWithLabels())
     * pools every room's photos together for the System Administrator and
     * Receptionist. Backfills by duplicating each type's existing gallery
     * files onto every room of that type (own copy per room, not a shared
     * file reference - so later per-room delete/replace can never affect
     * another room's copy), so nothing visibly regresses at cutover.
     * Individual room gallery images get no cooldown (only the Room Type's
     * main image keeps its 24h cooldown, untouched by this migration).
     */
    public function up(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('id')->constrained('rooms')->cascadeOnDelete();
        });

        // room_type_id is still NOT NULL at this point (it's dropped
        // outright below, once the per-room rows exist) - relax it first so
        // the new room-owned rows (which never set room_type_id) can insert.
        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->change();
        });

        $typeImages = DB::table('room_images')->get(['id', 'room_type_id', 'image_path', 'sort_order']);
        $roomsByType = DB::table('rooms')->select('id', 'room_type_id')->get()->groupBy('room_type_id');

        foreach ($typeImages as $image) {
            $rooms = $roomsByType->get($image->room_type_id) ?? collect();
            $ext = pathinfo($image->image_path, PATHINFO_EXTENSION);

            foreach ($rooms as $room) {
                $newPath = 'room-images/' . Str::uuid() . ($ext ? '.' . $ext : '');

                if (Storage::disk('public')->exists($image->image_path)) {
                    Storage::disk('public')->copy($image->image_path, $newPath);
                }

                DB::table('room_images')->insert([
                    'room_id' => $room->id,
                    'image_path' => $newPath,
                    'sort_order' => $image->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // The original type-owned rows/files are now superseded by the
        // per-room copies just inserted above.
        foreach ($typeImages as $image) {
            Storage::disk('public')->delete($image->image_path);
        }
        DB::table('room_images')->whereIn('id', $typeImages->pluck('id'))->delete();

        Schema::table('room_images', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
            $table->dropColumn(['room_type_id', 'image_changed_at']);
            $table->foreignId('room_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->after('id')->constrained('room_types')->cascadeOnDelete();
            $table->timestamp('image_changed_at')->nullable()->after('sort_order');
        });

        // Best-effort only: collapses every room's gallery back onto its
        // type's lowest-room_number room (mirrors the prior migration's own
        // down()). Rows belonging to any other room are not restorable to
        // their original room-owned form.
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
    }
};
