<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The photo gallery belongs to the room type: managed on the Edit Type page
 * (4-5 photos), shown for every room of the type and in the rooms API.
 */
class RoomTypeGalleryTest extends ApiFlowTestCase
{
    private function admin(): User
    {
        return User::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => 'admin@gallery.test', 'role' => 'admin']);
    }

    public function test_admin_uploads_up_to_five_photos_to_a_room_type(): void
    {
        Storage::fake('public');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $admin = $this->admin();
        $img = fn () => UploadedFile::fake()->image('p.jpg');

        $this->actingAs($admin)->post(route('admin.room-types.gallery.upload', $rt), ['images' => [$img(), $img(), $img(), $img()]])
            ->assertRedirect(route('admin.room-types.edit', $rt))->assertSessionHas('success');
        $this->assertSame(4, $rt->images()->count());
        $this->assertSame([0, 1, 2, 3], $rt->images()->pluck('sort_order')->all());

        // 4 + 2 would exceed 5
        $this->actingAs($admin)->post(route('admin.room-types.gallery.upload', $rt), ['images' => [$img(), $img()]])->assertSessionHas('error');
        $this->actingAs($admin)->post(route('admin.room-types.gallery.upload', $rt), ['images' => [$img()]])->assertSessionHas('success');
        $this->assertSame(5, $rt->images()->count());

        // every room of the type shows the type's gallery; the API keeps id/url/room_label
        $room = Room::where('room_type_id', $rt->id)->first();
        $this->assertCount(5, $room->gallery);
        $this->assertSame($rt->fresh()->gallery, $room->gallery);
        $this->assertSame(['id', 'url', 'room_label'], array_keys($rt->fresh()->gallery[0]));

        $this->actingAs($admin)->get(route('admin.room-types.edit', $rt))->assertOk()->assertSee('Gallery Images (5/5)');
        $this->actingAs($admin)->get(route('admin.rooms.edit', $room))->assertOk()
            ->assertDontSee('Gallery Images (')->assertSee("Edit Type page", false);
    }

    public function test_delete_keeps_the_minimum_and_replace_keeps_the_position(): void
    {
        Storage::fake('public');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $admin = $this->admin();
        foreach (range(0, 4) as $i) {
            RoomImage::create(['room_type_id' => $rt->id, 'image_path' => UploadedFile::fake()->image("p$i.jpg")->store('room-images', 'public'), 'sort_order' => $i]);
        }
        [$first, $second] = $rt->images()->get()->all();

        $this->actingAs($admin)->delete(route('admin.room-types.gallery.destroy', $first))->assertSessionHas('success');
        Storage::disk('public')->assertMissing($first->image_path);
        // at 4 photos, deleting is refused
        $this->actingAs($admin)->delete(route('admin.room-types.gallery.destroy', $second))->assertSessionHas('error');
        $this->assertSame(4, $rt->images()->count());

        $old = $second->image_path;
        $this->actingAs($admin)->put(route('admin.room-types.gallery.replace', $second), ['image' => UploadedFile::fake()->image('new.jpg')])
            ->assertSessionHas('success');
        $second->refresh();
        $this->assertNotSame($old, $second->image_path);
        $this->assertSame(1, $second->sort_order);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_photos_left_on_a_room_are_not_managed_or_shown(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $room = Room::where('room_type_id', $rt->id)->first();
        $legacy = RoomImage::create(['room_id' => $room->id, 'image_path' => 'room-images/old.jpg', 'sort_order' => 0]);

        $this->assertSame([], $rt->gallery);
        $this->assertSame([], $room->gallery);
        $this->actingAs($this->admin())->delete(route('admin.room-types.gallery.destroy', $legacy))->assertNotFound();
    }

    public function test_receptionist_cannot_change_the_gallery(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $desk = User::create(['first_name' => 'De', 'last_name' => 'Sk', 'email' => 'desk@gallery.test', 'role' => 'receptionist']);

        $this->actingAs($desk)->post(route('admin.room-types.gallery.upload', $rt), ['images' => [UploadedFile::fake()->image('p.jpg')]])
            ->assertRedirect('/');
        $this->assertSame(0, RoomImage::count());
    }

    public function test_migration_moves_the_fullest_room_gallery_to_the_type_without_duplicates(): void
    {
        Storage::fake('public');
        \Illuminate\Support\Facades\Schema::drop('room_images');
        \Illuminate\Support\Facades\Schema::create('room_images', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $t->string('image_path');
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        [$r1, $r2] = Room::where('room_type_id', $rt->id)->orderBy('room_number')->get()->all();
        $put = function (Room $room, string $content, int $order) {
            $path = 'room-images/' . uniqid() . '.jpg';
            Storage::disk('public')->put($path, $content);

            return \Illuminate\Support\Facades\DB::table('room_images')->insertGetId(['room_id' => $room->id, 'image_path' => $path, 'sort_order' => $order]);
        };
        $lone = $put($r1, 'one', 0);                               // room 1: a single photo
        $full = [$put($r2, 'a', 0), $put($r2, 'b', 1), $put($r2, 'a', 2), $put($r2, 'c', 3)];   // room 2: fullest, one duplicate

        (require base_path('database/migrations/2026_10_16_000001_move_gallery_to_room_types_again.php'))->up();

        $typeImages = \Illuminate\Support\Facades\DB::table('room_images')->where('room_type_id', $rt->id)->orderBy('sort_order')->pluck('id')->all();
        // room 2's distinct photos first (duplicate skipped), then room 1's
        $this->assertSame([$full[0], $full[1], $full[3], $lone], $typeImages);
        $this->assertNull(\Illuminate\Support\Facades\DB::table('room_images')->where('id', $lone)->value('room_id'));
        // the duplicate stays on its room, untouched
        $this->assertEquals($r2->id, \Illuminate\Support\Facades\DB::table('room_images')->where('id', $full[2])->value('room_id'));
        $this->assertSame(5, \Illuminate\Support\Facades\DB::table('room_images')->count());   // nothing deleted
    }
}
