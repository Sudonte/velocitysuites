<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Free/Included amenities (Wi-Fi, parking, air conditioning...) now come with every
 * room automatically - RoomType::getAmenitiesAttribute() lists every active free
 * amenity for every type - so they are no longer assigned per room type. Clears the
 * old free assignments; paid assignments (the only ones edited per type now) stay.
 * Nothing guests see is lost: every free amenity still shows on every type.
 */
return new class extends Migration
{
    public function up(): void
    {
        $freeIds = DB::table('amenities')->where('charge', '<=', 0)->pluck('id');

        DB::table('room_type_amenity')->whereIn('amenity_id', $freeIds)->delete();
    }

    public function down(): void
    {
        // Not restored: free amenities are implied for every type, so the rows carried no information.
    }
};
