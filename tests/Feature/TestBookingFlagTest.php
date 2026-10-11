<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\TestAccountScope;

/**
 * A walk-in test stay has no account to flag, so `bookings:mark-test` marks
 * the booking itself: it (and the reservation it came from) drops out of
 * every TestAccountScope-filtered figure, payments included, and
 * `bookings:unmark-test` brings it back.
 */
class TestBookingFlagTest extends ApiFlowTestCase
{
    private function walkInStay(): array
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $reservation = Reservation::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Walk', 'guest_last_name' => 'In',
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH,
        ]);
        $booking = Booking::create([
            'reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_COMPLETED, 'payment_method' => 'cash',
        ]);
        Payment::create([
            'booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 1000,
            'payment_status' => 'completed', 'payment_stage' => 'final', 'payment_date' => '2026-10-06', 'reference_number' => 'PAY-TEST1',
        ]);

        return [$reservation, $booking];
    }

    private function counts(): array
    {
        return [
            TestAccountScope::excludeFromReservations(Reservation::query())->count(),
            TestAccountScope::excludeFromBookings(Booking::query())->count(),
            (float) TestAccountScope::excludeFromPayments(Payment::query())->sum('amount_paid'),
        ];
    }

    public function test_marking_a_walk_in_booking_excludes_it_from_analytics_and_unmarking_restores_it(): void
    {
        [, $booking] = $this->walkInStay();
        $this->assertSame([1, 1, 1000.0], $this->counts());

        $this->artisan('bookings:mark-test', ['booking' => $booking->id, '--force' => true])->assertSuccessful();
        $this->assertTrue($booking->fresh()->is_test_booking);
        $this->assertSame([0, 0, 0.0], $this->counts());

        $this->artisan('bookings:unmark-test', ['booking' => $booking->id, '--force' => true])->assertSuccessful();
        $this->assertSame([1, 1, 1000.0], $this->counts());
    }

    public function test_non_numeric_id_is_refused(): void
    {
        $this->artisan('bookings:mark-test', ['booking' => 'abc', '--force' => true])->assertFailed();
    }
}
