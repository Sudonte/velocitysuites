<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\ReceiptService;
use App\Services\ReservationWorkflowService;
use App\Services\StayRecorder;
use App\Support\PaymentMath;

/**
 * A submitted GCash payment is NOT paid until the front desk verifies it: the paid total, the balance, the receipts' running totals and
 * the 20-50% rule all follow that. Submitted money is "waiting" (and still committed against the 50% cap); a rejected payment is neither.
 */
class PendingPaymentsNotPaidTest extends ApiFlowTestCase
{
    private function booking(): Booking
    {
        [, $guest] = $this->makeGuestUser('PP' . uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe' . uniqid(), 3500, 2, 2);
        $stays = [['check_in' => now('Asia/Manila')->addDays(5), 'check_out' => now('Asia/Manila')->addDays(7), 'lines' => [['room_type' => $rt, 'quantity' => 1]], 'amenities' => collect()]];
        $recorder = app(StayRecorder::class);
        $booking = Booking::create(array_merge($recorder->summaryColumns($stays), [
            'guest_id' => $guest->id, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'payment_method' => 'gcash',
        ]));
        $recorder->record($booking, $stays);

        return $booking->fresh();
    }

    private function gcash(Booking $b, array $o = []): Payment
    {
        return Payment::create(array_merge(['booking_id' => $b->id, 'payment_method' => 'gcash', 'reference_number' => '6564676886467', 'gcash_number' => '9171234567',
            'amount_paid' => 3500, 'payment_status' => 'completed', 'payment_stage' => 'deposit', 'payment_date' => now()], $o));
    }

    public function test_pending_is_waiting_not_paid_and_the_balance_uses_verified_money_only(): void
    {
        $b = $this->booking();
        $this->gcash($b);

        $this->assertEquals(0.0, $b->paidTotal());
        $this->assertEquals(3500.0, $b->waitingForVerificationTotal());
        $summary = app(ReceiptService::class)->paymentSummary($b->fresh());
        $this->assertEquals(7000.0, $summary['grand_total']);
        $this->assertEquals(0.0, $summary['total_amount_paid']);
        $this->assertEquals(3500.0, $summary['waiting_for_verification']);
        $this->assertEquals(7000.0, $summary['remaining_balance'], 'the balance is computed from verified payments only');
        $this->assertSame('PENDING', $summary['payment_status']);
        $tx = app(ReceiptService::class)->paymentTransactions($b->fresh())[0];
        $this->assertEquals(0.0, $tx['total_paid_after_transaction'], 'the running total does not move for a waiting payment');
        $this->assertSame('pending_verification', $tx['verification_status']);
    }

    public function test_verified_counts_and_leaves_waiting(): void
    {
        $b = $this->booking();
        $this->gcash($b, ['verified_at' => now(), 'verified_by' => 1]);

        $this->assertEquals(3500.0, $b->paidTotal());
        $this->assertEquals(0.0, $b->waitingForVerificationTotal());
        $summary = app(ReceiptService::class)->paymentSummary($b->fresh());
        $this->assertEquals(3500.0, $summary['total_amount_paid']);
        $this->assertEquals(3500.0, $summary['remaining_balance']);
        $this->assertSame('PARTIALLY_PAID', $summary['payment_status']);
    }

    public function test_rejected_is_neither_paid_nor_waiting_and_keeps_its_reason(): void
    {
        $b = $this->booking();
        $this->gcash($b, ['payment_status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => 'The reference number does not match.']);

        $this->assertEquals(0.0, $b->paidTotal());
        $this->assertEquals(0.0, $b->waitingForVerificationTotal());
        $tx = app(ReceiptService::class)->paymentTransactions($b->fresh())[0];
        $this->assertSame('rejected', $tx['verification_status']);
        $this->assertSame('The reference number does not match.', $tx['rejection_reason']);
    }

    public function test_cash_taken_at_the_desk_counts_immediately(): void
    {
        $b = $this->booking();
        Payment::create(['booking_id' => $b->id, 'payment_method' => 'cash', 'amount_paid' => 1000, 'payment_status' => 'completed', 'payment_stage' => 'final', 'payment_date' => now()]);
        $this->assertEquals(1000.0, $b->paidTotal());
        $this->assertEquals(0.0, $b->waitingForVerificationTotal());
    }

    public function test_the_fifty_percent_rule_counts_submitted_money_as_committed(): void
    {
        $setup = $this->booking();
        $reservation = Reservation::create([
            'guest_id' => $setup->guest_id, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'room_type_id' => $setup->room_type_id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(5)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(6)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'status' => Reservation::STATUS_AWAITING_GCASH, 'payment_method' => 'gcash',
        ]);
        \App\Models\ReservationRoomLine::create(['reservation_id' => $reservation->id, 'room_type_id' => $setup->room_type_id, 'room_type_name' => 'Deluxe',
            'quantity' => 1, 'price_per_night' => 3500, 'number_of_nights' => 1, 'subtotal' => 3500]);
        $workflow = app(ReservationWorkflowService::class);

        $before = $workflow->payableRange($reservation->fresh());
        $this->assertTrue($before['can_partial']);
        $this->assertEquals(1750.0, $before['max']);

        // 50% submitted and waiting: another payment cannot be taken online (it would settle the whole bill)
        Payment::create(['reservation_id' => $reservation->id, 'payment_method' => 'gcash', 'amount_paid' => 1750, 'payment_status' => 'pending', 'payment_stage' => 'deposit', 'payment_date' => now()]);
        $after = $workflow->payableRange($reservation->fresh());
        $this->assertFalse($after['can_partial'], 'the waiting 50% is committed');
        $this->assertEquals(1750.0, $after['paid'], 'verified + waiting: the amount already submitted');

        // a rejected one frees the money again
        Payment::where('reservation_id', $reservation->id)->update(['payment_status' => 'rejected', 'rejected_at' => now()]);
        $this->assertTrue($workflow->payableRange($reservation->fresh())['can_partial']);
    }

    public function test_the_rule_for_models_and_arrays_agrees(): void
    {
        $b = $this->booking();
        $p = $this->gcash($b);
        $this->assertFalse($p->countsAsPaid());
        $this->assertTrue($p->isWaitingForVerification());
        $this->assertSame(PaymentMath::totalPaid([$p]), PaymentMath::totalPaid([$p->toArray()]));
        $p->update(['verified_at' => now()]);
        $this->assertTrue($p->fresh()->countsAsPaid());
    }
}
