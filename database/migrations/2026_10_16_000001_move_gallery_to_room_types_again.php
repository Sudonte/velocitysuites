<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * The photo gallery moves from each individual room to the Room Type: the
     * System Administrator manages one 4-5 photo gallery per type (on the Edit
     * Type page) and every room of the type shows it.
     *
     * Backfill, per type: the photos of its room with the fullest gallery, then its other rooms,
     * skipping exact duplicates (the rooms' galleries began as copies of one
     * type gallery), first 5 kept. Those rows are re-pointed to the type. Every
     * other room photo is left exactly as it is (row and file) - no longer
     * shown anywhere, but nothing is deleted.
     */
    public function up(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->after('id')->constrained('room_types')->cascadeOnDelete();
        });
        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->change();
        });

        $disk = Storage::disk('public');
        $images = DB::table('room_images')
            ->join('rooms', 'rooms.id', '=', 'room_images.room_id')
            ->get(['room_images.id', 'room_images.image_path', 'room_images.sort_order', 'room_images.room_id', 'rooms.room_type_id', 'rooms.room_number']);
        $perRoom = $images->countBy('room_id');
        // The room with the fullest gallery first, then the type's other rooms.
        $images = $images->sortBy([
            ['room_type_id', 'asc'],
            fn ($a, $b) => $perRoom[$b->room_id] <=> $perRoom[$a->room_id],
            ['room_number', 'asc'],
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ]);

        foreach ($images->groupBy('room_type_id') as $typeId => $rows) {
            $seen = [];
            $order = 0;
            foreach ($rows as $row) {
                if ($order >= 5) {
                    break;
                }
                $hash = $disk->exists($row->image_path) ? md5($disk->get($row->image_path)) : null;
                if ($hash === null || isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                DB::table('room_images')->where('id', $row->id)
                    ->update(['room_type_id' => $typeId, 'room_id' => null, 'sort_order' => $order++, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Best effort: hand each type's photos to its first room again.
        $first = DB::table('rooms')->selectRaw('room_type_id, MIN(id) AS room_id')->groupBy('room_type_id')->pluck('room_id', 'room_type_id');
        foreach (DB::table('room_images')->whereNotNull('room_type_id')->get(['id', 'room_type_id']) as $row) {
            if (isset($first[$row->room_type_id])) {
                DB::table('room_images')->where('id', $row->id)->update(['room_id' => $first[$row->room_type_id]]);
            } else {
                DB::table('room_images')->where('id', $row->id)->delete();
            }
        }
        Schema::table('room_images', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
            $table->dropColumn('room_type_id');
        });
        Schema::table('room_images', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable(false)->change();
        });
    }
};
