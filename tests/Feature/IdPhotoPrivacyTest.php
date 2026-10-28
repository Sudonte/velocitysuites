<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Reservation;
use App\Services\StayOffersService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A guest's ID photo is private: it is stored on the private disk (never the public one, so there is no URL for it), and only the
 * guest who owns it (and staff, through the receptionist pages) can open it.
 */
class IdPhotoPrivacyTest extends ApiFlowTestCase
{
    public function test_an_id_photo_is_stored_privately_and_has_no_public_copy(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $senior = Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'status' => 'active']);
        $booking = Booking::create(['room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => today()->addDay(), 'check_out' => today()->addDays(2), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash']);

        app(StayOffersService::class)->attachDiscount($booking, $senior->id, UploadedFile::fake()->image('id.jpg'));

        $path = $booking->fresh()->id_card_image_path;
        $this->assertStringStartsWith('id-cards/', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing of it is reachable through the public disk');
    }

    public function test_only_the_owner_can_open_their_own_id_photo_through_the_api(): void
    {
        Storage::fake('local');
        [$owner, $ownerGuest] = $this->makeGuestUser('IdOwner');
        [$other] = $this->makeGuestUser('IdOther');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        Storage::disk('local')->put('id-cards/mine.jpg', 'bytes');
        $reservation = Reservation::create(['guest_id' => $ownerGuest->id, 'guest_first_name' => 'A', 'guest_last_name' => 'B', 'room_type_id' => $rt->id,
            'rooms_requested' => 1, 'check_in' => today()->addDays(3)->toDateString(), 'check_out' => today()->addDays(4)->toDateString(), 'adults' => 1, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH, 'id_card_image_path' => 'id-cards/mine.jpg']);
        $booking = Booking::create(['guest_id' => $ownerGuest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => today()->addDays(3), 'check_out' => today()->addDays(4), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'id_card_image_path' => 'id-cards/mine.jpg']);

        $this->actingAs($owner);
        $this->assertSame(200, app(ReservationController::class)->showIdCard($reservation)->getStatusCode());
        $this->assertSame(200, app(BookingController::class)->showIdCard($booking)->getStatusCode());

        $this->actingAs($other);
        $this->assertSame(403, app(ReservationController::class)->showIdCard($reservation)->getStatusCode(), 'another guest is refused');
        $this->assertSame(403, app(BookingController::class)->showIdCard($booking)->getStatusCode());
    }

    public function test_the_receptionist_id_pages_need_a_logged_in_receptionist(): void
    {
        Storage::fake('local');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        Storage::disk('local')->put('id-cards/g.jpg', 'bytes');
        $booking = Booking::create(['room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => today()->addDays(3), 'check_out' => today()->addDays(4), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'id_card_image_path' => 'id-cards/g.jpg']);
        [$guestUser] = $this->makeGuestUser('NotStaff');
        $desk = \App\Models\User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'idp-desk@example.test', 'password' => bcrypt('x'),
            'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $url = route('receptionist.bookings.id-card', $booking);

        $this->get($url)->assertRedirect(); // not signed in
        $refused = $this->actingAs($guestUser)->get($url);
        $this->assertContains($refused->getStatusCode(), [302, 403], 'a guest is turned away from the receptionist page');
        $this->assertStringNotContainsString('bytes', (string) $refused->getContent());
        $this->actingAs($desk)->get($url)->assertOk();
    }
}
