<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Http\Request;

/**
 * Removing the frozen "Completed" copy of a converted reservation hides only that copy.
 */
class SnapshotHideAndOtpOrderTest extends ApiFlowTestCase
{
    private function converted(int $guestId, int $roomTypeId, string $bookingStatus): Reservation
    {
        $r = Reservation::create([
            'guest_id' => $guestId, 'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'guest_first_name' => 'G', 'guest_last_name' => 'Snap',
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_CONVERTED, 'payment_method' => 'cash',
        ]);
        Booking::create([
            'reservation_id' => $r->id, 'guest_id' => $guestId, 'room_type_id' => $roomTypeId, 'rooms_requested' => 1,
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => $bookingStatus, 'payment_method' => 'cash',
        ]);

        return $r;
    }

    private function snapshotDelete($user, Reservation $r)
    {
        $this->actingAs($user);
        $req = Request::create('/api/guest/reservations/' . $r->id, 'DELETE', ['snapshot_only' => 1]);
        $req->setUserResolver(fn () => $user);
        app()->instance('request', $req);

        return app(ReservationController::class)->destroy($r);
    }

    public function test_snapshot_of_a_booking_that_is_still_open_can_be_hidden_without_touching_the_booking(): void
    {
        [$user, $guest] = $this->makeGuestUser('Snap1');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = $this->converted($guest->id, $rt->id, Booking::STATUS_ACTIVE);

        $res = $this->snapshotDelete($user, $r);

        $this->assertSame(200, $res->getStatusCode());
        $r->refresh();
        $this->assertNotNull($r->snapshot_hidden_at);
        $this->assertNull($r->hidden_at, 'the reservation row itself stays listed');
        $this->assertNull($r->hidden_by_guest_at);
        $this->assertNull($r->booking->hidden_at, 'the booking is untouched');
        $this->assertSame(Booking::STATUS_ACTIVE, $r->booking->booking_status);
    }

    public function test_someone_elses_snapshot_cannot_be_hidden(): void
    {
        [$user] = $this->makeGuestUser('SnapMe');
        [, $other] = $this->makeGuestUser('SnapOther');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = $this->converted($other->id, $rt->id, Booking::STATUS_ACTIVE);

        $res = $this->snapshotDelete($user, $r);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertNull($r->fresh()->snapshot_hidden_at);
    }

    public function test_a_reservation_that_never_became_a_booking_has_no_snapshot_to_hide(): void
    {
        [$user, $guest] = $this->makeGuestUser('SnapNone');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'G', 'guest_last_name' => 'X',
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH, 'payment_method' => 'cash',
        ]);

        $res = $this->snapshotDelete($user, $r);

        $this->assertSame(409, $res->getStatusCode());
        $this->assertNull($r->fresh()->snapshot_hidden_at);
    }
}
