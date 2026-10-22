<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\RoomController;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Room browsing: the app shows how many rooms of each type are free for the chosen dates, never lets a guest add more than that, and
 * the server refuses an over-booking with a clear message that says how many are left.
 */
class RoomAvailabilityRuleTest extends ApiFlowTestCase
{
    private function book($user, $rt1, $rt2)
    {
        $request = Request::create('/api/guest/bookings', 'POST',
            $this->bookingRequestPayload($rt1, $rt2, 'Avail', 2000, (string) Str::uuid(), 'REF-' . Str::random(10)));
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    public function test_asking_for_more_rooms_than_are_free_is_refused_and_says_how_many_are_left(): void
    {
        [$user] = $this->makeGuestUser('Avail1');
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 1); // the booking asks for 2 Suites, only 1 exists

        $response = $this->book($user, $rt1, $rt2);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertSame('No more Suite rooms available for these dates (1 available).', $response->getData(true)['message']);
        $this->assertSame(0, Booking::count(), 'nothing was created');
    }

    public function test_a_fully_booked_type_says_so(): void
    {
        [$user] = $this->makeGuestUser('Avail2');
        $rt1 = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $rt2 = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 0);

        $response = $this->book($user, $rt1, $rt2);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Suite is fully booked for these dates.', $response->getData(true)['message']);
    }

    public function test_the_room_list_reports_how_many_rooms_are_available_for_the_chosen_dates(): void
    {
        [$user] = $this->makeGuestUser('Avail3');
        $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $this->makeRoomTypeWithRooms('Suite', 2000, 4, 0);
        $in = now('Asia/Manila')->addDays(5)->toDateString();
        $out = now('Asia/Manila')->addDays(7)->toDateString();

        $request = Request::create('/api/rooms', 'GET', ['check_in' => $in, 'check_out' => $out]);
        $data = app(RoomController::class)->index($request)->getData(true);
        $byName = collect($data['room_types']['data'])->keyBy('name');

        $this->assertSame(3, $byName['Deluxe']['available_count']);
        $this->assertFalse($byName['Deluxe']['is_fully_booked']);
        $this->assertSame(0, $byName['Suite']['available_count']);
        $this->assertTrue($byName['Suite']['is_fully_booked']);
    }
}
