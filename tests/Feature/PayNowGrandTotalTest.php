<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Models\Amenity;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The pay-now range is 20%-50% of the GRAND total (rooms + amenities, before any discount), on the server exactly as in the app.
 * One night: Deluxe 3,500 + Executive 5,800 = 9,300 of rooms, plus 4 x 100 of amenities = 9,700, so 1,940.00 to 4,850.00.
 * (Rooms alone would give 1,860.00 to 4,650.00: the amenities must be in the base.)
 */
class PayNowGrandTotalTest extends ApiFlowTestCase
{
    private function submit(float $amount)
    {
        static $n = 0;
        $n++;
        [$user] = $this->makeGuestUser('Gt' . $n);
        $this->actingAs($user);
        $deluxe = \App\Models\RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $executive = \App\Models\RoomType::where('name', 'Executive')->first() ?? $this->makeRoomTypeWithRooms('Executive', 5800, 2, 3);
        $towel = Amenity::where('amenity_name', 'Towel')->first()
            ?? Amenity::create(['amenity_name' => 'Towel', 'description' => 'd', 'category' => 'Room', 'quantity' => 50, 'charge' => 100, 'status' => 'active']);
        $request = Request::create('/api/guest/bookings', 'POST', [
            'stays' => [[
                'check_in' => now('Asia/Manila')->addDays(5)->toDateString(),
                'check_out' => now('Asia/Manila')->addDays(6)->toDateString(),
                'rooms' => [['room_type_id' => $deluxe->id, 'quantity' => 1], ['room_type_id' => $executive->id, 'quantity' => 1]],
                'amenities' => [
                    ['amenity_id' => $towel->id, 'quantity' => 3, 'room_type_id' => $deluxe->id, 'room_slot' => 1],
                    ['amenity_id' => $towel->id, 'quantity' => 1, 'room_type_id' => $executive->id, 'room_slot' => 1],
                ],
            ]],
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'GT-' . Str::random(8), 'gcash_number' => '9171234567',
            'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    public function test_the_range_is_twenty_to_fifty_percent_of_rooms_plus_amenities(): void
    {
        foreach ([1940.00, 3000.00, 4850.00] as $amount) {
            $response = $this->submit($amount);
            $this->assertSame(201, $response->getStatusCode(), $amount . ' ' . $response->getContent());
        }
        $this->assertSame(3, Booking::count());
        $this->assertEquals(9700.0, (float) $response->getData(true)['total_amount_due']);
    }

    public function test_outside_that_range_is_refused_and_says_the_grand_total_range(): void
    {
        foreach ([1860.00, 1939.99, 4850.01] as $amount) {
            $response = $this->submit($amount);
            $this->assertSame(422, $response->getStatusCode(), $amount . ' ' . $response->getContent());
            $this->assertStringContainsString('₱1,940.00 and ₱4,850.00', $response->getData(true)['message']);
            $this->assertStringContainsString('₱9,700.00 total', $response->getData(true)['message']);
        }
        $this->assertSame(0, Booking::count());
    }
}
