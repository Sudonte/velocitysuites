<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Http\Request;

/**
 * Guests may remove only finished reservations (cancelled / rejected / whose booking is closed). Removal is a soft
 * hide: the row stays for staff, billing and reports, and only the guest's own list stops returning it.
 */
class GuestDeleteEligibilityTest extends ApiFlowTestCase
{
    private function reservation(int $guestId, int $roomTypeId, string $status): Reservation
    {
        return Reservation::create([
            'guest_id' => $guestId, 'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'guest_first_name' => 'G', 'guest_last_name' => 'Del',
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => $status, 'payment_method' => 'cash',
        ]);
    }

    private function destroyAs($user, Reservation $r): int
    {
        $this->actingAs($user);
        app()->instance('request', Request::create('/x', 'DELETE'));

        return app(ReservationController::class)->destroy($r)->getStatusCode();
    }

    private function listedIds($user): array
    {
        $this->actingAs($user);
        $req = Request::create('/api/guest/reservations', 'GET');
        $req->setUserResolver(fn () => $user);
        $body = json_decode(app(ReservationController::class)->index($req)->getContent());

        return array_map(fn ($r) => $r->id, $body->data);
    }

    public function test_active_reservations_cannot_be_deleted_even_through_the_api(): void
    {
        [$user, $guest] = $this->makeGuestUser('DelA');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        foreach ([Reservation::STATUS_AWAITING_CASH, Reservation::STATUS_AWAITING_GCASH] as $status) {
            $r = $this->reservation($guest->id, $rt->id, $status);
            $this->assertSame(409, $this->destroyAs($user, $r), $status);
            $this->assertNull($r->fresh()->hidden_at);
        }

        $converted = $this->reservation($guest->id, $rt->id, Reservation::STATUS_CONVERTED);
        Booking::create([
            'reservation_id' => $converted->id, 'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash',
        ]);
        $this->assertSame(409, $this->destroyAs($user, $converted), 'a live booking cannot be deleted');
        $this->assertNull($converted->fresh()->hidden_at);
    }

    public function test_cancelled_and_rejected_are_hidden_from_the_guest_list_but_kept_in_the_table(): void
    {
        [$user, $guest] = $this->makeGuestUser('DelB');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $cancelled = $this->reservation($guest->id, $rt->id, Reservation::STATUS_CANCELLED);
        $rejected = $this->reservation($guest->id, $rt->id, Reservation::STATUS_REJECTED);
        $kept = $this->reservation($guest->id, $rt->id, Reservation::STATUS_AWAITING_CASH);
        $this->assertEqualsCanonicalizing([$cancelled->id, $rejected->id, $kept->id], $this->listedIds($user));

        $this->assertSame(200, $this->destroyAs($user, $cancelled));
        $this->assertSame(200, $this->destroyAs($user, $rejected));

        $this->assertSame([$kept->id], $this->listedIds($user), 'gone from the list at once');
        $this->assertNotNull(Reservation::find($cancelled->id), 'the row stays for staff, billing and reports');
        $this->assertNotNull(Reservation::find($cancelled->id)->hidden_by_guest_at);
        $this->assertSame(Reservation::STATUS_CANCELLED, Reservation::find($cancelled->id)->status, 'status untouched');
    }

    public function test_someone_elses_reservation_cannot_be_deleted(): void
    {
        [$user] = $this->makeGuestUser('DelMe');
        [, $other] = $this->makeGuestUser('DelOther');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = $this->reservation($other->id, $rt->id, Reservation::STATUS_CANCELLED);

        $this->assertSame(403, $this->destroyAs($user, $r));
        $this->assertNull($r->fresh()->hidden_at);
    }

    private function convertedWithBooking(int $guestId, int $roomTypeId, string $bookingStatus): Reservation
    {
        $r = $this->reservation($guestId, $roomTypeId, Reservation::STATUS_CONVERTED);
        Booking::create([
            'reservation_id' => $r->id, 'guest_id' => $guestId, 'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => $bookingStatus, 'payment_method' => 'cash',
        ]);

        return $r;
    }

    public function test_a_completed_reservation_can_be_deleted_and_the_row_stays(): void
    {
        [$user, $guest] = $this->makeGuestUser('DelC');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = $this->convertedWithBooking($guest->id, $rt->id, Booking::STATUS_COMPLETED);
        $this->assertContains($r->id, $this->listedIds($user));

        $this->assertSame(200, $this->destroyAs($user, $r));

        $this->assertNotContains($r->id, $this->listedIds($user), 'gone from the guest list');
        $this->assertNotNull(Reservation::find($r->id), 'the row stays for staff, billing and reports');
        $this->assertNotNull(Booking::find($r->booking->id), 'and so does its booking');
    }

    public function test_hiding_the_completed_copy_of_a_converted_reservation_leaves_the_booking_visible(): void
    {
        [$user, $guest] = $this->makeGuestUser('DelD');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = $this->convertedWithBooking($guest->id, $rt->id, Booking::STATUS_ACTIVE);
        $bookingId = $r->booking->id;

        $this->actingAs($user);
        app()->instance('request', Request::create('/x', 'DELETE', ['snapshot_only' => 1]));
        $this->assertSame(200, app(ReservationController::class)->destroy($r)->getStatusCode());

        // The app builds the Booking list from this same reservation row (its nested booking), so the row must
        // still be listed, with its booking attached and not hidden.
        $this->assertContains($r->id, $this->listedIds($user));
        $fresh = Reservation::find($r->id);
        $this->assertNotNull($fresh->snapshot_hidden_at);
        $this->assertNull($fresh->hidden_at);
        $booking = Booking::find($bookingId);
        $this->assertNotNull($booking, 'receptionist side still has the booking');
        $this->assertNull($booking->hidden_at);
        $this->assertNull($booking->hidden_by_guest_at);
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->booking_status);
    }
}
