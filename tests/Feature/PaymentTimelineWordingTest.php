<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Reservation;
use App\Support\TransactionTimeline;

/**
 * A guest's GCash payment is 'completed' the moment it is submitted, but it is only VERIFIED once the front desk stamps it. The timeline
 * must say "awaiting verification" until then (it once said "Payment verified" for every completed payment, e.g. on Booking #592).
 */
class PaymentTimelineWordingTest extends ApiFlowTestCase
{
    private function reservationWithGcashPayment(array $paymentOverrides = []): array
    {
        [$user] = $this->makeGuestUser('TL1');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);
        $reservation = Reservation::create([
            'guest_id' => $user->guest->id, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'room_type_id' => $rt->id,
            'rooms_requested' => 1, 'check_in' => now('Asia/Manila')->addDays(5)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(6)->toDateString(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'status' => Reservation::STATUS_AWAITING_GCASH, 'payment_method' => 'gcash',
        ]);
        $payment = Payment::create(array_merge([
            'reservation_id' => $reservation->id, 'payment_method' => 'gcash', 'reference_number' => '6564676886467', 'gcash_number' => '9171234567',
            'amount_paid' => 3500, 'payment_status' => 'completed', 'payment_stage' => 'deposit', 'payment_date' => now(),
        ], $paymentOverrides));

        return [$reservation->fresh(), $payment];
    }

    private function paymentStep(array $timeline, Payment $payment): array
    {
        return collect($timeline)->firstWhere('key', 'payment_' . $payment->id);
    }

    public function test_a_submitted_gcash_payment_is_awaiting_verification_not_verified(): void
    {
        [$reservation, $payment] = $this->reservationWithGcashPayment();
        $step = $this->paymentStep(TransactionTimeline::forReservation($reservation), $payment);

        $this->assertSame('Payment awaiting verification', $step['label']);
        $this->assertSame(TransactionTimeline::PENDING, $step['status']);
        $this->assertNotContains('Payment verified', array_column(TransactionTimeline::forReservation($reservation), 'label'));
    }

    public function test_once_the_front_desk_verifies_it_the_timeline_says_so(): void
    {
        [$reservation, $payment] = $this->reservationWithGcashPayment(['verified_at' => now(), 'verified_by' => 1]);
        $step = $this->paymentStep(TransactionTimeline::forReservation($reservation), $payment);

        $this->assertSame('Payment verified', $step['label']);
        $this->assertSame(TransactionTimeline::VERIFIED, $step['status']);
    }

    public function test_cash_the_desk_recorded_itself_reads_as_received_not_awaiting(): void
    {
        [$reservation, $payment] = $this->reservationWithGcashPayment(['payment_method' => 'cash', 'reference_number' => 'CHECKIN-1', 'gcash_number' => null]);
        $step = $this->paymentStep(TransactionTimeline::forReservation($reservation), $payment);

        $this->assertSame('Payment verified', $step['label'], 'cash has no verification step');
    }
}
