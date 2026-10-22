<?php

namespace Tests\Feature;

use App\Models\Receipt;

/** The web PDF prints the stored receipts with the same titles and money lines as the app, and never an adjustment line. */
class ReceiptPdfMatchesScreenTest extends ApiFlowTestCase
{
    public function test_the_pdf_view_prints_the_three_receipts_like_the_app(): void
    {
        $mk = fn (int $type, string $no, array $h) => new Receipt([
            'booking_id' => 1, 'receipt_type' => $type, 'receipt_no' => $no, 'total_amount' => 2000, 'total_paid' => 600, 'remaining_balance' => 1400,
            'snapshot' => ['highlight' => $h],
        ]);
        $receipts = collect([
            $mk(1, 'PR-1', ['kind' => 'booking_verification', 'total_amount' => 2000, 'amount_paid_now' => 600, 'total_paid' => 600, 'remaining_balance' => 1400]),
            $mk(2, 'CI-1', ['kind' => 'checkin_payment', 'total_amount' => 2000, 'previous_payments' => 600, 'amount_paid_now' => 1400, 'total_paid' => 2000, 'remaining_balance' => 0]),
            $mk(3, 'OR-1', ['kind' => 'checkout_final', 'room_charges' => 2000, 'discount' => 0, 'amenities_by_room' => [['room' => '101', 'items' => [['name' => 'Towel', 'quantity' => 2, 'subtotal' => 100]]]],
                'amenities_total' => 100, 'other_charges' => [['name' => 'Late check-out', 'amount' => 500]], 'additional_charges_total' => 500, 'grand_total' => 2600,
                'payments' => [['label' => 'Payment verified', 'amount' => 600]], 'total_paid' => 2600, 'remaining_balance' => 0, 'no_refund_note' => 'No refunds for early check-out (hotel policy).']),
        ]);
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $reservation = new \App\Models\Reservation(['check_in' => now(), 'check_out' => now()->addDay(), 'number_of_guests' => 1]);
        $reservation->id = 7;
        $reservation->setRelation('roomType', $rt);
        $reservation->setRelation('payments', collect());
        $reservation->setRelation('booking', null);

        $html = view('guest.reservations.receipt-pdf', [
            'reservation' => $reservation,
            'paymentSummary' => ['official_receipt_available' => true, 'grand_total' => 2600, 'total_amount_paid' => 2600, 'remaining_balance' => 0],
            'paymentTransactions' => [],
            'storedReceipts' => $receipts,
        ])->render();

        foreach (['Payment verified', 'Check-in', 'Check-out (final)', 'Remaining balance due at the hotel', 'Paid at check-in', 'Room charges (booked nights)',
                  'Late check-out', 'No refunds for early check-out (hotel policy).'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('adjustment', strtolower($html));
    }
}
