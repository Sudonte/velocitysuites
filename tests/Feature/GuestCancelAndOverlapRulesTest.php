<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Reservation;
use App\Services\ReservationWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guest cancel gate (Cash reservation verified by staff, anything already
 * converted to a Booking incl. GCash Pay Later, closed reservations) and the
 * removal of the guest's own overlapping-stay block.
 */
class GuestCancelAndOverlapRulesTest extends ApiFlowTestCase
{
    private function reservation(int $roomTypeId, array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'room_type_id' => $roomTypeId, 'rooms_requested' => 1, 'guest_first_name' => 'G', 'guest_last_name' => uniqid(),
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH, 'payment_method' => 'cash',
        ], $overrides));
    }

    private function assertCancelRefused(Reservation $r): void
    {
        try {
            app(ReservationWorkflowService::class)->cancel($r);
            $this->fail('cancel() should have been refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertNotSame(Reservation::STATUS_CANCELLED, $r->fresh()->status);
    }

    public function test_unverified_cash_reservation_can_be_cancelled(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = $this->reservation($rt->id);
        app(ReservationWorkflowService::class)->cancel($r);
        $this->assertSame(Reservation::STATUS_CANCELLED, $r->fresh()->status);
    }

    public function test_cash_reservation_verified_by_staff_cannot_be_cancelled(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $this->assertCancelRefused($this->reservation($rt->id, ['verified_at' => now()]));
    }

    public function test_gcash_pay_later_reservation_converted_to_booking_cannot_be_cancelled(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $r = $this->reservation($rt->id, ['payment_method' => 'gcash', 'status' => Reservation::STATUS_CONVERTED]);
        Booking::create([
            'reservation_id' => $r->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-12-10', 'check_out' => '2026-12-12', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'gcash',
        ]);
        $this->assertCancelRefused($r);
    }

    public function test_rejected_or_already_cancelled_reservation_cannot_be_cancelled_again(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 2);
        $this->assertCancelRefused($this->reservation($rt->id, ['status' => Reservation::STATUS_REJECTED]));
        try {
            app(ReservationWorkflowService::class)->cancel($this->reservation($rt->id, ['status' => Reservation::STATUS_CANCELLED]));
            $this->fail('cancel() should have been refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_same_guest_can_create_two_reservations_for_the_same_and_overlapping_dates(): void
    {
        [$user, ] = $this->makeGuestUser('Overlap');
        $this->actingAs($user);
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 5);
        $controller = app(\App\Http\Controllers\Api\ReservationController::class);

        $post = function (string $in, string $out) use ($controller, $rt, $user) {
            $req = Request::create('/api/guest/reservations', 'POST', [
                'rooms' => [['room_type_id' => $rt->id, 'quantity' => 1]],
                'check_in' => $in, 'check_out' => $out, 'adults' => 1, 'children' => 0,
                'guest_first_name' => 'ClaudeTest', 'guest_last_name' => 'Overlap',
                'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
            ]);
            $req->setUserResolver(fn () => $user);
            return $controller->store($req);
        };

        $in = now('Asia/Manila')->addDays(3)->toDateString();
        $out = now('Asia/Manila')->addDays(5)->toDateString();
        $overlapOut = now('Asia/Manila')->addDays(7)->toDateString();
        foreach ([[$in, $out], [$in, $out], [now('Asia/Manila')->addDays(4)->toDateString(), $overlapOut]] as [$i, $o]) {
            $res = $post($i, $o);
            $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        }
    }
}
