<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Online payment for a booking is a partial (down) payment only: 20%-50% of the total, never the whole bill. The rest is paid at the
 * hotel. 2 nights x (1 x 1,000 + 2 x 2,000) = 10,000, so a down payment is 2,000 to 5,000.
 */
class BookingPartialPaymentOnlyTest extends ApiFlowTestCase
{
    private function submit(float $amount)
    {
        static $n = 0;
        $n++;
        [$user] = $this->makeGuestUser('Part' . $n);
        $this->actingAs($user);
        $rt1 = \App\Models\RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 12);
        $rt2 = \App\Models\RoomType::where('name', 'Suite')->first() ?? $this->makeRoomTypeWithRooms('Suite', 2000, 4, 12);
        $request = Request::create('/api/guest/bookings', 'POST',
            $this->bookingRequestPayload($rt1, $rt2, 'Part' . $n, $amount, (string) Str::uuid(), 'REF-' . Str::random(10)));
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);

        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    public function test_paying_the_whole_total_online_is_refused_and_creates_no_booking(): void
    {
        $response = $this->submit(10000);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('partial payment only', $response->getContent());
        $this->assertSame(0, Booking::count());
    }

    public function test_a_payment_outside_twenty_to_fifty_percent_is_refused_with_the_range(): void
    {
        $low = $this->submit(1999.99);
        $high = $this->submit(5000.01);

        foreach ([$low, $high] as $response) {
            $this->assertSame(422, $response->getStatusCode(), $response->getContent());
            $this->assertStringContainsString('₱2,000.00 and ₱5,000.00', $response->getData(true)['message']);
        }
        $this->assertSame(0, Booking::count());
    }

    public function test_a_down_payment_inside_the_range_creates_the_booking_as_a_deposit(): void
    {
        foreach ([2000, 3500, 5000] as $amount) {
            $response = $this->submit($amount);
            $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        }
        $this->assertSame(3, Booking::count());
        $this->assertSame(['deposit'], \App\Models\Payment::pluck('payment_stage')->unique()->values()->all());
    }
}
