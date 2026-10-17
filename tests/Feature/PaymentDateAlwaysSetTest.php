<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\ReceiptService;

/**
 * Receipts print Payment Date / Payment Time from payments.payment_date, so a payment must never be saved without one,
 * and a legacy row that already has none still reports its verified / created time instead of a blank.
 */
class PaymentDateAlwaysSetTest extends ApiFlowTestCase
{
    private function booking(): Booking
    {
        [, $guest] = $this->makeGuestUser('PD'.uniqid());
        $rt = $this->makeRoomTypeWithRooms('PD'.uniqid(), 1000, 1, 2);

        return Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => \App\Support\CheckInWindow::date()->toDateString(), 'check_out' => \App\Support\CheckInWindow::date()->addDay()->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'cash',
        ]);
    }

    public function test_a_payment_created_without_a_date_gets_the_moment_it_was_created(): void
    {
        $booking = $this->booking();
        $payment = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 500,
            'payment_stage' => 'deposit', 'payment_status' => 'pending']);

        $this->assertNotNull($payment->fresh()->payment_date);
    }

    public function test_a_payment_that_was_given_a_date_keeps_it(): void
    {
        $booking = $this->booking();
        $at = now()->subDays(3)->startOfSecond();
        $payment = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 500,
            'payment_stage' => 'deposit', 'payment_status' => 'pending', 'payment_date' => $at]);

        $this->assertTrue($payment->fresh()->payment_date->equalTo($at));
    }

    public function test_a_legacy_payment_with_no_date_reports_when_it_was_created_in_the_receipt_history(): void
    {
        $booking = $this->booking();
        $payment = Payment::create(['booking_id' => $booking->id, 'payment_method' => 'cash', 'amount_paid' => 500,
            'payment_stage' => 'deposit', 'payment_status' => 'completed']);
        \Illuminate\Support\Facades\DB::table('payments')->where('id', $payment->id)->update(['payment_date' => null]);

        $rows = app(ReceiptService::class)->paymentTransactions($booking->fresh());

        $this->assertNotEmpty($rows[0]['payment_date']);
    }
}
