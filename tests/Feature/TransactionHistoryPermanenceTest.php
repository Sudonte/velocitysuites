<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\Request;

/**
 * A guest removing a booking / reservation only takes it off Bookings & Reservations. The record, its billing and its
 * payments are never deleted, and Transaction History (include_hidden=1) still lists it with the real status.
 */
class TransactionHistoryPermanenceTest extends ApiFlowTestCase
{
    private function directBooking(string $status, $guest): Booking
    {
        $rt = $this->makeRoomTypeWithRooms('P'.uniqid(), 1000, 2, 1);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'check_in' => '2026-10-05', 'check_out' => '2026-10-06',
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => $status, 'payment_method' => 'cash',
        ]);
        $billing = Billing::create(['booking_id' => $booking->id, 'room_charge' => 1000, 'total_amount' => 1000, 'billing_status' => 'paid']);
        Payment::create(['booking_id' => $booking->id, 'billing_id' => $billing->id, 'payment_method' => 'cash', 'amount_paid' => 1000, 'payment_status' => 'completed', 'payment_stage' => 'final', 'payment_date' => now()]);

        return $booking;
    }

    private function list(string $controller, $user, array $query = []): array
    {
        $request = Request::create('/x', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return json_decode(app($controller)->index($request)->getContent(), true)['data'];
    }

    public function test_removing_a_direct_booking_keeps_every_row_and_it_stays_in_transaction_history(): void
    {
        [$user, $guest] = $this->makeGuestUser('Perm1');
        $booking = $this->directBooking(Booking::STATUS_COMPLETED, $guest);
        $this->actingAs($user);

        $res = app(BookingController::class)->destroy($booking);
        $this->assertSame(200, $res->getStatusCode());

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Billing::count());
        $this->assertSame(1, Payment::count());
        $this->assertTrue($booking->fresh()->hidden_by_guest);

        $this->assertCount(0, $this->list(BookingController::class, $user), 'gone from Bookings & Reservations');
        $history = $this->list(BookingController::class, $user, ['include_hidden' => 1]);
        $this->assertCount(1, $history, 'still in Transaction History');
        $this->assertTrue($history[0]['hidden_by_guest']);
        $this->assertSame(Booking::STATUS_COMPLETED, $history[0]['booking_status'], 'with its real status');
    }

    public function test_removing_a_converted_reservation_keeps_reservation_booking_billing_and_payments(): void
    {
        [$user, $guest] = $this->makeGuestUser('Perm2');
        $rt = $this->makeRoomTypeWithRooms('Q'.uniqid(), 1000, 2, 1);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'status' => Reservation::STATUS_CONVERTED,
        ]);
        $booking = Booking::create([
            'reservation_id' => $reservation->id, 'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'booking_status' => Booking::STATUS_COMPLETED, 'payment_method' => 'cash',
        ]);
        $billing = Billing::create(['booking_id' => $booking->id, 'room_charge' => 1000, 'total_amount' => 1000, 'billing_status' => 'paid']);
        Payment::create(['billing_id' => $billing->id, 'reservation_id' => $reservation->id, 'payment_method' => 'cash', 'amount_paid' => 1000, 'payment_status' => 'completed', 'payment_stage' => 'final', 'payment_date' => now()]);
        $this->actingAs($user);

        $this->assertSame(200, app(ReservationController::class)->destroy($reservation)->getStatusCode());

        $this->assertSame(1, Reservation::count());
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Billing::count());
        $this->assertSame(1, Payment::count());
        $this->assertCount(0, $this->list(ReservationController::class, $user));
        $history = $this->list(ReservationController::class, $user, ['include_hidden' => 1]);
        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['hidden_by_guest']);
        $this->assertSame(Booking::STATUS_COMPLETED, $history[0]['booking']['booking_status']);
    }

    public function test_an_active_transaction_cannot_be_removed(): void
    {
        [$user, $guest] = $this->makeGuestUser('Perm3');
        $booking = $this->directBooking(Booking::STATUS_ACTIVE, $guest);
        $this->actingAs($user);

        $this->assertSame(409, app(BookingController::class)->destroy($booking)->getStatusCode());
        $this->assertFalse($booking->fresh()->hidden_by_guest);
    }

    public function test_a_booking_staff_soft_deleted_is_still_in_the_guests_history(): void
    {
        [$user, $guest] = $this->makeGuestUser('Perm4');
        $booking = $this->directBooking(Booking::STATUS_CANCELLED, $guest);
        $booking->delete(); // the receptionist's soft delete

        $this->assertCount(0, $this->list(BookingController::class, $user));
        $this->assertCount(1, $this->list(BookingController::class, $user, ['include_hidden' => 1]));
    }

    public function test_no_guest_or_staff_code_path_hard_deletes_a_transaction_record(): void
    {
        // forceDelete()/DELETE on bookings, reservations, billings or payments must not exist outside the retired archive service.
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getFilename(), 'TransactionArchiveService.php')) {
                continue;
            }
            $code = php_strip_whitespace($file->getPathname());
            if (preg_match('/(booking|reservation|billing|payment)s?(\(\))?->forceDelete\(|DB::table\(\'(bookings|reservations|billings|payments)\'\)(->where[^;]*)?->delete\(/i', $code)) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders);
    }
}
