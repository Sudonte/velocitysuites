<?php

namespace Tests\Feature;

use App\Models\RoomType;
use App\Models\User;

/** An archived (inactive) room type is hidden by default everywhere; the full-screen photo viewer is the one shared viewer. */
class ArchivedRoomTypesAndPhotoViewerTest extends ApiFlowTestCase
{
    private function admin(): User
    {
        return User::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => 'admin@archived.test', 'role' => 'admin']);
    }

    public function test_the_admin_room_types_list_shows_active_types_by_default_with_archived_and_all_filters(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe Room', 1200, 2, 1);
        $old = $this->makeRoomTypeWithRooms('Studio', 200, 4, 1);
        $old->update(['status' => 'inactive']);
        $admin = $this->admin();

        $default = $this->actingAs($admin)->get(route('admin.room-types.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Deluxe Room', $default);
        $this->assertStringNotContainsString('Studio', $default);
        $this->assertStringContainsString('<option value="active" selected>Active</option>', preg_replace('/\s+/', ' ', str_replace("\n", ' ', $default)) ?: '');

        $archived = $this->actingAs($admin)->get(route('admin.room-types.index', ['status' => 'archived']))->assertOk()->getContent();
        $this->assertStringContainsString('Studio', $archived);
        $this->assertStringNotContainsString('Deluxe Room', $archived);

        $all = $this->actingAs($admin)->get(route('admin.room-types.index', ['status' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('Studio', $all);
        $this->assertStringContainsString('Deluxe Room', $all);
    }

    public function test_archived_types_never_reach_guests_or_the_booking_choices(): void
    {
        $this->makeRoomTypeWithRooms('Deluxe Room', 1200, 2, 2);
        $old = $this->makeRoomTypeWithRooms('Studio', 200, 4, 2);
        $old->update(['status' => 'inactive']);
        $json = $this->getJson('/api/rooms')->assertOk()->json('room_types.data');
        $this->assertSame(['Deluxe Room'], array_column($json, 'name'));
        $this->assertStringNotContainsString('Studio', $this->get('/rooms')->assertOk()->getContent());
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk@archived.test', 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $this->assertStringNotContainsString('Studio', $this->actingAs($desk)->get(route('receptionist.bookings.create'))->getContent());
        $this->assertStringNotContainsString('Studio', $this->actingAs($desk)->get(route('receptionist.reservations.create'))->getContent());
    }

    public function test_the_full_screen_viewer_is_one_shared_script_with_the_required_behaviour(): void
    {
        $js = file_get_contents(public_path('js/photo-viewer.js'));
        foreach (['object-fit:contain', 'image-orientation:from-image', "' of '", 'Image unavailable', 'vspv-spin', "e.key === 'Escape'", "'ArrowLeft'", "'ArrowRight'", 'touchstart', 'touchend', 'aria-modal'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertStringNotContainsString('object-fit:cover', $js, 'the full-screen photo is never cropped');
        $component = file_get_contents(resource_path('views/components/room-gallery.blade.php'));
        $this->assertStringContainsString('js/photo-viewer.js', $component);
        $this->assertStringNotContainsString('modal-fullscreen', $component, 'the old stretched full-screen modal is gone');
        foreach (['js/portal/rooms.js'] as $f) {
            $this->assertStringContainsString('VSPhotoViewer', file_get_contents(public_path($f)));
        }
        $rt = $this->makeRoomTypeWithRooms('Deluxe Room', 1200, 2, 1);
        $this->assertStringContainsString('js/photo-viewer.js', $this->get('/rooms/' . $rt->id)->assertOk()->getContent(), 'the guest room page loads the viewer');
    }

    public function test_every_gallery_photo_url_the_api_gives_the_app_is_the_original_stored_file(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe Room', 1200, 2, 1);
        \App\Models\RoomImage::create(['room_type_id' => $rt->id, 'image_path' => 'room-images/abc.jpg', 'sort_order' => 1]);
        $row = collect($this->getJson('/api/rooms')->assertOk()->json('room_types.data'))->firstWhere('name', 'Deluxe Room');
        $this->assertCount(1, $row['gallery']);
        $this->assertStringEndsWith('/storage/room-images/abc.jpg', $row['gallery'][0]['url'], 'the file itself, not a resized copy');
    }
}
