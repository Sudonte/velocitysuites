<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Models\Booking;
use App\Models\Payment;

/**
 * A guest can cancel a BOOKING any time until the receptionist verifies it - whatever has been paid. After verification (and once the
 * stay has started) it can no longer be cancelled. The partial payment is non-refundable.
 */
class BookingCancelRuleTest extends ApiFlowTestCase
{
    private function booking($guest, array $attributes = []): Booking
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe' . uniqid(), 1000, 2, 1);

        return Booking::create(array_merge([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => now('Asia/Manila')->addDays(5)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(7)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(),
        ], $attributes));
    }

    private function pay(Booking $booking, float $amount): void
    {
        Payment::create([
            'booking_id' => $booking->id, 'payment_method' => 'gcash', 'amount_paid' => $amount, 'payment_status' => 'pending',
            'payment_stage' => 'deposit', 'payment_date' => now(),
        ]);
    }

    private function cancel($user, Booking $booking)
    {
        $this->actingAs($user);

        return app(BookingController::class)->cancel($booking->fresh());
    }

    public function test_an_unverified_booking_can_be_cancelled_with_a_partial_payment(): void
    {
        [$user, $guest] = $this->makeGuestUser('Can1');
        $booking = $this->booking($guest);
        $this->pay($booking, 600);

        $response = $this->cancel($user, $booking);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->booking_status);
    }

    public function test_an_unverified_booking_can_be_cancelled_even_when_it_is_fully_paid(): void
    {
        [$user, $guest] = $this->makeGuestUser('Can2');
        $booking = $this->booking($guest);
        $this->pay($booking, 2000); // an older, fully paid booking: still cancellable until it is verified

        $this->assertSame(200, $this->cancel($user, $booking)->getStatusCode());
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->booking_status);
    }

    public function test_a_verified_booking_cannot_be_cancelled_and_the_message_says_why(): void
    {
        [$user, $guest] = $this->makeGuestUser('Can3');
        $booking = $this->booking($guest, ['verified_at' => now()]);
        $this->pay($booking, 600);

        $response = $this->cancel($user, $booking);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('verified by the front desk', $response->getData(true)['message']);
        $this->assertStringContainsString('can no longer be cancelled', $response->getData(true)['message']);
        $this->assertSame(Booking::STATUS_ACTIVE, $booking->fresh()->booking_status);
    }

    public function test_a_checked_in_booking_cannot_be_cancelled(): void
    {
        [$user, $guest] = $this->makeGuestUser('Can4');
        $booking = $this->booking($guest, ['booking_status' => Booking::STATUS_CHECKED_IN]);

        $this->assertSame(422, $this->cancel($user, $booking)->getStatusCode());
    }
}
