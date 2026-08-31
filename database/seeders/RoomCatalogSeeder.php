<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomImage;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RoomCatalogSeeder extends Seeder
{
    /**
     * Reconciles the 5 required room types (Standard/Deluxe/Superior/
     * Grand Family Suite/Executive) with their required room counts,
     * capacities, bed configurations, descriptions, profile images, and
     * 4-5-image galleries. Fully idempotent - safe to re-run any number
     * of times; only ever tops up what's genuinely missing (by room
     * count and per-room image count), never creates duplicates.
     *
     * Source images live in storage/app/seed-source-images/ (18 verified,
     * real hotel-room-style stock photos, individually reviewed before
     * use - not random/unrelated placeholder images) and are copied into
     * the normal public-disk locations exactly like a real admin upload
     * would produce, so they behave identically to any other stored image
     * from this point on.
     */
    private const SOURCE_DIR = 'seed-source-images';

    public function run(): void
    {
        // "Suite" is the closest existing match for "Grand Family Suite"
        // (same capacity, one real room already under it) - renamed in
        // place rather than left behind as an orphaned duplicate type.
        RoomType::where('name', 'Suite')->update(['name' => 'Grand Family Suite']);

        // Not part of the required 5-type catalog - deactivated (not
        // deleted) so any real history tied to them is preserved; the
        // admin can reactivate any of them later if needed.
        RoomType::whereIn('name', ['Honeymoon', 'Bryan Dela Cruz', 'Ombid'])->update(['status' => 'inactive']);

        foreach ($this->catalog() as $entry) {
            $roomType = RoomType::updateOrCreate(
                ['name' => $entry['name']],
                [
                    'rate' => $entry['rate'],
                    'capacity' => $entry['capacity'],
                    'bed_type' => $entry['bed_type'],
                    'description' => $entry['type_description'],
                    'number_format' => $entry['number_format'],
                    'status' => 'active',
                ]
            );

            if (! $roomType->image) {
                $roomType->update(['image' => $this->storeSeedImage($entry['profile_image'], 'room-type-images')]);
            }

            $shortfall = max(0, $entry['room_count'] - $roomType->rooms()->count());

            if ($shortfall > 0) {
                foreach ($roomType->nextRoomNumbers($shortfall) as $number) {
                    Room::create([
                        'room_number' => $number,
                        'room_name' => $entry['name'] . ' Room',
                        'room_type_id' => $roomType->id,
                        'room_capacity' => $entry['capacity'],
                        'description' => $entry['room_description'],
                        'status' => 'available',
                    ]);
                }
            }

            foreach ($roomType->rooms()->get() as $room) {
                // Keep every room of this type consistent with the
                // reconciled spec, including rooms that already existed
                // before this seeder ran (e.g. Standard's original room
                // had capacity 2; the required spec is 1).
                $room->fill([
                    'room_name' => $entry['name'] . ' Room',
                    'room_capacity' => $entry['capacity'],
                    'description' => $entry['room_description'],
                ]);
                if ($room->isDirty()) {
                    $room->save();
                }

                // Tops up from wherever this room currently is to the
                // type's full curated gallery size (4 or 5) - never
                // re-adds images a prior run already created, and never
                // exceeds the 5-image maximum since $entry['gallery']
                // itself is never longer than 5.
                $targetCount = count($entry['gallery']);
                $existingCount = $room->images()->count();

                if ($existingCount < $targetCount) {
                    $nextSortOrder = ($room->images()->max('sort_order') ?? -1) + 1;
                    $toAdd = array_slice($entry['gallery'], $existingCount, $targetCount - $existingCount);

                    foreach ($toAdd as $filename) {
                        RoomImage::create([
                            'room_id' => $room->id,
                            'image_path' => $this->storeSeedImage($filename, 'room-images'),
                            'sort_order' => $nextSortOrder++,
                        ]);
                    }
                }
            }
        }
    }

    private function catalog(): array
    {
        return [
            [
                'name' => 'Standard',
                'rate' => 2000,
                'capacity' => 1,
                'bed_type' => '1 Single Bed',
                'number_format' => '2##',
                'room_count' => 3,
                'type_description' => 'Standard Rooms offer a comfortable, no-fuss stay for the solo traveler, each fitted with a single bed and all the everyday essentials.',
                'room_description' => 'A cozy single-occupancy room designed for one guest, featuring a comfortable single bed, warm wood-toned flooring, and soft ambient lighting. Ideal for solo travelers or short business stays who want a clean, quiet, and budget-friendly place to rest.',
                'profile_image' => '271618.jpg',
                'gallery' => ['271618.jpg', '271624.jpg', '271639.jpg', '1454804.jpg'],
            ],
            [
                'name' => 'Deluxe',
                'rate' => 3500,
                'capacity' => 2,
                'bed_type' => '2 Matrimonial Beds',
                'number_format' => '3##',
                'room_count' => 3,
                'type_description' => 'Deluxe Rooms pair two matrimonial beds with a private balcony view, giving couples or friends extra room to relax without compromising on comfort.',
                'room_description' => 'A bright, well-proportioned room with two matrimonial beds, a private balcony, and warm modern furnishings. Comfortably accommodates two guests and suits couples, friends, or family members who each want their own bed while sharing the room.',
                'profile_image' => '210604.jpg',
                'gallery' => ['210604.jpg', '210265.jpg', '6438756.jpg', '1454804.jpg'],
            ],
            [
                'name' => 'Superior',
                'rate' => 4200,
                'capacity' => 2,
                'bed_type' => '2 Single Beds',
                'number_format' => '4##',
                'room_count' => 3,
                'type_description' => 'Superior Rooms feature two single beds beneath a vaulted ceiling, a versatile layout suited to friends, siblings, or colleagues sharing a stay.',
                'room_description' => 'A spacious twin-bed room with two separate single beds, a vaulted ceiling, and an airy, upscale atmosphere. Well suited to friends, siblings, or colleagues who are traveling together but prefer their own separate bed.',
                'profile_image' => '262048.jpg',
                'gallery' => ['262048.jpg', '279746.jpg', '1454806.jpg', '1454804.jpg'],
            ],
            [
                'name' => 'Grand Family Suite',
                'rate' => 6500,
                'capacity' => 4,
                'bed_type' => '2 Matrimonial Beds',
                'number_format' => '1##',
                'room_count' => 4,
                'type_description' => 'The Grand Family Suite combines two matrimonial beds with its own separate living area, built for families or groups of up to four who want space to spread out.',
                'room_description' => 'A generous family-sized suite with two matrimonial beds and its own separate living area, comfortably sleeping up to four guests. The extra space and relaxed, homely atmosphere make it ideal for families or small groups traveling together.',
                'profile_image' => '271643.jpg',
                'gallery' => ['271643.jpg', '276724.jpg', '189333.jpg', '2029667.jpg', '1454804.jpg'],
            ],
            [
                'name' => 'Executive',
                'rate' => 5800,
                'capacity' => 2,
                'bed_type' => '1 King-Size Bed',
                'number_format' => '6##',
                'room_count' => 5,
                'type_description' => 'Executive Rooms are anchored by a single king-size bed and a dedicated work/lounge nook, tailored to business travelers and guests who want a premium stay.',
                'room_description' => 'An elevated room centered on a single king-size bed, with a dedicated lounge/work nook and rich, contemporary finishes. Comfortably suited to two guests, and especially well suited to business travelers or couples wanting a premium, more spacious stay.',
                'profile_image' => '237371.jpg',
                'gallery' => ['237371.jpg', '5998135.jpg', '2029670.jpg', '6480707.jpg', '1454804.jpg'],
            ],
        ];
    }

    private function storeSeedImage(string $sourceFilename, string $destinationFolder): string
    {
        $source = storage_path('app/' . self::SOURCE_DIR . '/' . $sourceFilename);
        $extension = pathinfo($sourceFilename, PATHINFO_EXTENSION);
        $destination = $destinationFolder . '/' . Str::random(40) . '.' . $extension;

        Storage::disk('public')->put($destination, File::get($source));

        return $destination;
    }
}
