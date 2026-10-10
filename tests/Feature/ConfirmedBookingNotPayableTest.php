<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Decision: once a reservation has become a booking, the rest of the balance is paid at the front desk.
 * The endpoint keeps refusing (422 + {message}, so older apps read it the same way) but now says how much is left.
 */
class ConfirmedBookingNotPayableTest extends ApiFlowTestCase
{
    private function confirmedBooking($guest, float $paid): Reservation
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3); // 2 nights x 1,000 = 2,000
        $r = new Reservation();
        $r->forceFill([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->startOfDay(), 'check_out' => now('Asia/Manila')->addDays(5)->startOfDay(),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'status' => Reservation::STATUS_CONVERTED, 'payment_method' => 'gcash',
        ])->save();

        $b = new Booking();
        $b->forceFill([
            'reservation_id' => $r->id, 'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => $r->check_in, 'check_out' => $r->check_out, 'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'gcash',
        ])->save();

        if ($paid > 0) {
            $p = new Payment();
            $p->forceFill([
                'reservation_id' => $r->id, 'payment_method' => 'gcash', 'amount_paid' => $paid, 'payment_status' => 'completed',
                'payment_stage' => 'deposit', 'payment_date' => now()->subDay(), 'verified_at' => now()->subHours(2), 'verified_by' => 1,
            ])->save();
        }

        return $r->fresh();
    }

    private function pay($user, Reservation $r)
    {
        $request = Request::create("/api/guest/reservations/{$r->id}/payments", 'POST', [
            'payment_method' => 'gcash', 'payment_type' => 'full', 'amount_paid' => 1400,
            'reference_number' => (string) random_int(1000000000000, 9999999999999), 'gcash_number' => '9171234567',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return app(PaymentController::class)->store($request, $r);
    }

    public function test_a_confirmed_booking_with_a_balance_is_told_to_pay_at_the_front_desk(): void
    {
        [$user, $guest] = $this->makeGuestUser('Desk1');
        $r = $this->confirmedBooking($guest, 600);

        $response = $this->pay($user, $r);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'Remaining balance: ₱1,400.00. Please pay at the Velocity Suites front desk.'],
            json_decode($response->getContent(), true),
            'same status and same {message} shape as before'
        );
        $this->assertSame(1, Payment::where('reservation_id', $r->id)->count(), 'nothing was recorded');
    }

    public function test_a_fully_paid_booking_says_so_instead_of_naming_a_balance(): void
    {
        [$user, $guest] = $this->makeGuestUser('Desk2');
        $r = $this->confirmedBooking($guest, 2000);

        $response = $this->pay($user, $r);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('fully paid', $response->getContent());
        $this->assertStringNotContainsString('front desk', $response->getContent());
    }

    public function test_a_cancelled_reservation_keeps_the_generic_message(): void
    {
        [$user, $guest] = $this->makeGuestUser('Desk3');
        $r = $this->confirmedBooking($guest, 0);
        $r->forceFill(['status' => Reservation::STATUS_CANCELLED])->save();

        $response = $this->pay($user, $r->fresh());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['message' => 'This reservation is not payable.'], json_decode($response->getContent(), true));
    }
}
