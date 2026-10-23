<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ReservationController;
use Illuminate\Http\Request;

/**
 * The guest's reservation LIST eager-loads the room lines; they must still come back in the mapped `room_lines` shape (room_type name, nights,
 * subtotal), not as the raw table rows (room_type_name, number_of_nights) - the app showed "1x null" and "Deluxe x4" when the raw rows won.
 */
class ReservationListShapeTest extends ApiFlowTestCase
{
    public function test_the_list_keeps_the_mapped_room_lines_with_names_and_each_stays_own_nights(): void
    {
        [$user] = $this->makeGuestUser('RL1');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 4);
        $exec = $this->makeRoomTypeWithRooms('Executive', 5800, 2, 4);
        $this->actingAs($user);
        $day = fn (int $n) => now('Asia/Manila')->addDays($n)->toDateString();
        $create = Request::create('/api/guest/reservations', 'POST', [
            'stays' => [
                ['check_in' => $day(6), 'check_out' => $day(7), 'rooms' => [['room_type_id' => $deluxe->id, 'quantity' => 1], ['room_type_id' => $exec->id, 'quantity' => 1]]],
                ['check_in' => $day(7), 'check_out' => $day(8), 'rooms' => [['room_type_id' => $deluxe->id, 'quantity' => 1], ['room_type_id' => $exec->id, 'quantity' => 1]]],
            ],
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'payment_method' => 'gcash',
        ]);
        $create->setUserResolver(fn () => $user);
        $this->assertSame(201, app(ReservationController::class)->store($create)->getStatusCode());

        $request = Request::create('/api/guest/reservations', 'GET');
        $request->setUserResolver(fn () => $user);
        $row = app(ReservationController::class)->index($request)->getData(true)['data'][0];

        $this->assertCount(4, $row['room_lines']);
        $this->assertSame(['Deluxe', 'Executive', 'Deluxe', 'Executive'], array_column($row['room_lines'], 'room_type'));
        $this->assertSame([1, 1, 1, 1], array_column($row['room_lines'], 'nights'), 'each line its own stay\'s one night');
        $this->assertEquals(18600.0, array_sum(array_column($row['room_lines'], 'subtotal')));
        $this->assertArrayNotHasKey('room_type_name', $row['room_lines'][0], 'not the raw table row');
        $this->assertEquals(18600.0, (float) $row['total_amount_due']);
    }
}
