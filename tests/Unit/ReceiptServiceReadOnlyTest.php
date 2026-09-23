<?php

namespace Tests\Unit;

use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\ReceiptService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests proving ReceiptService's read methods
 * (paymentSummary()/paymentTransactions()/receiptsList()) are genuinely
 * side-effect-free - deliberately plain PHPUnit\Framework\TestCase (no
 * Laravel app boot, no database). Every Booking/Billing/Payment/Reservation
 * instance is built in-memory via setRawAttributes()/setRelation() -
 * never saved, never queried.
 *
 * The proof mechanism: a plain PHPUnit\Framework\TestCase has NO database
 * connection resolver at all. If any of these read methods still called
 * Payment::ensureReceiptNumber()/Billing::ensureOfficialReceiptNumber()
 * (both of which enter DB::transaction()/lockForUpdate() the moment a
 * payment/billing actually qualifies), the test would throw "Call to a
 * member function connection() on null" immediately. Every test below
 * deliberately uses payments/billings that DO qualify by business rule
 * (verified deposit, fully-paid+completed-checkout billing) but have
 * receipt_number = null (simulating a historical record, or simply
 * "never explicitly issued") - a passing assertion is direct proof the
 * read path never attempted to mint one, and never silently backfilled
 * receipt_number on the object either (checked explicitly below).
 *
 * ReceiptService itself has no constructor dependencies - instantiated
 * directly (new ReceiptService()), not via app()/the container, so no
 * Laravel bootstrap is needed at all.
 */
class ReceiptServiceReadOnlyTest extends TestCase
{
    private function service(): ReceiptService
    {
        return new ReceiptService();
    }

    private function fakePayment(array $attributes): Payment
    {
        $payment = new Payment();
        $payment->setRawAttributes(array_merge([
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'payment_stage' => 'deposit',
            'amount_paid' => 1000.0,
            'verified_at' => null,
            'rejected_at' => null,
            'receipt_number' => null,
            'billing_id' => null,
            'reservation_id' => null,
            'booking_id' => null,
            'reference_number' => null,
            'gcash_number' => null,
            'rejection_reason' => null,
            'payment_date' => Carbon::parse('2026-09-20 07:45:00'),
            'created_at' => Carbon::parse('2026-09-20 07:45:00'),
        ], $attributes));

        return $payment;
    }

    private function fakeBilling(array $attributes): Billing
    {
        $billing = new Billing();
        $billing->setRawAttributes(array_merge([
            'total_amount' => 10000.0,
            'billing_status' => 'pending',
            'receipt_number' => null,
            'updated_at' => Carbon::parse('2026-09-23 11:31:00'),
        ], $attributes));

        return $billing;
    }

    /**
     * Defaults booking_id-scoped payments() to an empty collection and
     * billing/reservation to null - Booking::allPayments() unconditionally
     * reads $this->payments (never guarded by reservation_id, unlike the
     * reservation/billing branches), so leaving it un-cached would attempt
     * a real lazy-load query and crash immediately in this DB-less test.
     * Callers override reservation/billing afterward via their own
     * setRelation() calls where a test actually needs them populated.
     */
    private function fakeBooking(array $attributes): Booking
    {
        $booking = new Booking();
        $booking->setRawAttributes(array_merge([
            'id' => 439,
            'reservation_id' => null,
            'selected_payment_percentage' => 50.0,
            'booking_status' => Booking::STATUS_CHECKED_IN,
        ], $attributes));
        $booking->setRelation('payments', collect([]));
        $booking->setRelation('billing', null);
        $booking->setRelation('reservation', null);
        // buildReceiptPayload() reads roomType/rooms (for room_type/
        // room_lines/assigned_room_numbers) and account_guest_full_name
        // reads guest() directly when reservation_id is null - pre-wiring
        // every belongsTo/belongsToMany relation this model exposes means
        // property access always hits the relation cache, never attempts
        // to construct a real query (which needs a DB connection
        // regardless of whether anything would actually be queried).
        $booking->setRelation('roomType', null);
        $booking->setRelation('rooms', collect([]));
        $booking->setRelation('guest', null);

        return $booking;
    }

    // ---- §8: historical null receipt_number stays null after reads -----

    public function test_payment_transactions_never_backfills_a_null_receipt_number(): void
    {
        $deposit = $this->fakePayment([
            'id' => 1,
            'amount_paid' => 5000.0,
            'verified_at' => Carbon::parse('2026-09-20 08:00:00'),
            'receipt_number' => null, // "historical" - qualifies, never issued
        ]);
        $booking = $this->fakeBooking(['id' => 439]);
        $booking->setRelation('payments', collect([]));
        $booking->setRelation('billing', null);
        $booking->setRelation('reservation', null);
        // No billing at all yet - grandTotal() would fall back to
        // total_amount_due, which touches other relations/queries this
        // test doesn't fake. Give it a billing so grandTotal() resolves
        // from billing->total_amount instead.
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'partial', 'receipt_number' => null]);
        $billing->setRelation('payments', collect([$deposit]));
        $booking->setRelation('billing', $billing);

        $transactions = $this->service()->paymentTransactions($booking);

        $this->assertCount(1, $transactions);
        $this->assertNull($transactions[0]['receipt_type']);
        $this->assertNull($transactions[0]['receipt_number']);
        // The underlying model attribute itself was never mutated either.
        $this->assertNull($deposit->receipt_number);
    }

    public function test_receipts_list_omits_a_qualifying_payment_that_was_never_actually_issued_a_number(): void
    {
        $deposit = $this->fakePayment([
            'id' => 1,
            'amount_paid' => 5000.0,
            'verified_at' => Carbon::parse('2026-09-20 08:00:00'),
            'receipt_number' => null,
        ]);
        $booking = $this->fakeBooking(['id' => 439]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'partial', 'receipt_number' => null]);
        $billing->setRelation('payments', collect([$deposit]));
        $booking->setRelation('billing', $billing);

        $receipts = $this->service()->receiptsList($booking);

        $this->assertSame([], $receipts);
    }

    public function test_receipts_list_omits_official_receipt_when_billing_receipt_number_never_issued_even_if_otherwise_eligible(): void
    {
        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_COMPLETED]);
        // billing_status=paid + booking_status=COMPLETED -> the business
        // rule (isOfficialReceiptAvailable()) is TRUE, but receipt_number
        // was never actually minted (simulating a historical/pre-feature
        // record) - must still be omitted, never backfilled.
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid', 'receipt_number' => null]);
        $billing->setRelation('payments', collect([]));
        $billing->setRelation('booking', $booking); // inverse relation, so the sanity check below doesn't need a real query
        $booking->setRelation('billing', $billing);

        $this->assertTrue($billing->isOfficialReceiptAvailable(), 'sanity check: business rule should read true');

        $receipts = $this->service()->receiptsList($booking);

        $this->assertSame([], $receipts);
        $this->assertNull($billing->receipt_number);
    }

    public function test_receipts_list_includes_receipts_that_were_already_issued(): void
    {
        $deposit = $this->fakePayment(['id' => 1, 'amount_paid' => 5000.0, 'receipt_number' => 'PR-20260920-000001']);
        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_COMPLETED]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid', 'receipt_number' => 'OR-20260923-000082']);
        $billing->setRelation('payments', collect([$deposit]));
        $booking->setRelation('billing', $billing);

        $receipts = $this->service()->receiptsList($booking);

        $this->assertCount(2, $receipts);
        $types = array_column($receipts, 'receipt_type');
        $this->assertContains('PARTIAL_RECEIPT', $types);
        $this->assertContains('OFFICIAL_RECEIPT', $types);
    }

    // ---- §3 (backend review): checkout-completion-aware Official Receipt ----

    public function test_official_receipt_not_available_when_billing_paid_but_still_checked_in(): void
    {
        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_CHECKED_IN]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid']);
        $billing->setRelation('payments', collect([]));
        $booking->setRelation('billing', $billing);

        $summary = $this->service()->paymentSummary($booking);

        $this->assertFalse($summary['official_receipt_available']);
    }

    public function test_official_receipt_available_when_billing_paid_and_booking_completed(): void
    {
        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_COMPLETED]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid']);
        $billing->setRelation('payments', collect([]));
        $booking->setRelation('billing', $billing);

        $summary = $this->service()->paymentSummary($booking);

        $this->assertTrue($summary['official_receipt_available']);
    }

    // ---- Money correctness through the full read pipeline --------------

    public function test_rejected_and_failed_payments_never_increase_total_amount_paid(): void
    {
        $verified = $this->fakePayment(['id' => 1, 'amount_paid' => 5000.0, 'verified_at' => Carbon::parse('2026-09-20 08:00:00')]);
        $rejected = $this->fakePayment(['id' => 2, 'amount_paid' => 3000.0, 'payment_status' => 'rejected', 'rejected_at' => Carbon::parse('2026-09-20 09:00:00')]);
        $failed = $this->fakePayment(['id' => 3, 'amount_paid' => 2000.0, 'payment_status' => 'failed']);

        $booking = $this->fakeBooking(['id' => 439]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'partial']);
        $billing->setRelation('payments', collect([$verified, $rejected, $failed]));
        $booking->setRelation('billing', $billing);

        $summary = $this->service()->paymentSummary($booking);

        $this->assertSame(5000.0, $summary['total_amount_paid']);
        $this->assertSame(5000.0, $summary['remaining_balance']);
    }

    public function test_cash_checkout_payment_is_included_in_payment_history_and_total_paid(): void
    {
        $deposit = $this->fakePayment(['id' => 1, 'amount_paid' => 5000.0, 'verified_at' => Carbon::parse('2026-09-20 08:00:00')]);
        $checkoutCash = $this->fakePayment([
            'id' => 2, 'payment_method' => 'cash', 'payment_stage' => 'final',
            'billing_id' => 82, 'amount_paid' => 5000.0, 'verified_at' => null,
            'payment_date' => Carbon::parse('2026-09-23 11:30:00'),
        ]);

        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_COMPLETED]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid']);
        $billing->setRelation('payments', collect([$deposit, $checkoutCash]));
        $booking->setRelation('billing', $billing);

        $summary = $this->service()->paymentSummary($booking);
        $transactions = $this->service()->paymentTransactions($booking);

        $this->assertSame(10000.0, $summary['total_amount_paid']);
        $this->assertSame(0.0, $summary['remaining_balance']);
        $this->assertSame('PAID', $summary['payment_status']);
        $this->assertCount(2, $transactions);
        $cashRow = $transactions[1];
        $this->assertSame('cash', $cashRow['payment_method']);
        $this->assertSame('CHECKOUT_PAYMENT', $cashRow['transaction_type']);
        $this->assertSame(10000.0, $cashRow['total_paid_after_transaction']);
    }

    // ---- §F: converted reservation history merges without duplicates ---

    public function test_converted_reservation_history_merges_reservation_and_billing_payments_without_duplicates(): void
    {
        // The deposit payment was made while this was still a reservation
        // (reservation_id set), then re-parented onto the billing at
        // checkout (Receptionist\CheckOutController::refreshStayCharges()
        // sets billing_id WITHOUT clearing reservation_id) - so it
        // legitimately appears in both the reservation->payments AND
        // billing->payments sources. The checkout cash payment only ever
        // has billing_id set.
        $deposit = $this->fakePayment([
            'id' => 1, 'reservation_id' => 100, 'billing_id' => 82,
            'amount_paid' => 5000.0, 'verified_at' => Carbon::parse('2026-09-20 08:00:00'),
        ]);
        $checkoutCash = $this->fakePayment([
            'id' => 2, 'payment_method' => 'cash', 'payment_stage' => 'final',
            'billing_id' => 82, 'amount_paid' => 5000.0, 'verified_at' => null,
            'payment_date' => Carbon::parse('2026-09-23 11:30:00'),
        ]);

        $reservation = new Reservation();
        $reservation->setRawAttributes(['id' => 100]);
        $reservation->setRelation('payments', collect([$deposit]));

        $booking = $this->fakeBooking(['id' => 250, 'reservation_id' => 100, 'booking_status' => Booking::STATUS_COMPLETED]);
        $booking->setRelation('reservation', $reservation);
        $booking->setRelation('payments', collect([])); // booking_id-scoped - none for a reservation-derived booking

        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid']);
        $billing->setRelation('payments', collect([$deposit, $checkoutCash]));
        $booking->setRelation('billing', $billing);

        $all = $booking->allPayments();

        $this->assertCount(2, $all, 'the re-parented deposit payment must not be double-counted');
        $this->assertSame([1, 2], $all->pluck('id')->sort()->values()->all());

        $summary = $this->service()->paymentSummary($booking);
        $this->assertSame(10000.0, $summary['total_amount_paid']);
    }

    // ---- §6 (final checkpoint): receipt history uniqueness -------------

    public function test_receipts_list_never_duplicates_a_payment_reachable_from_multiple_sources(): void
    {
        // Same re-parenting scenario as the converted-reservation test
        // above (payment id=1 legitimately appears in BOTH
        // reservation->payments AND billing->payments) - the PARTIAL_RECEIPT
        // entry for it must appear exactly once, not twice.
        $deposit = $this->fakePayment([
            'id' => 1, 'reservation_id' => 100, 'billing_id' => 82,
            'amount_paid' => 5000.0, 'verified_at' => Carbon::parse('2026-09-20 08:00:00'),
            'receipt_number' => 'PR-20260920-000001',
        ]);

        $reservation = new Reservation();
        $reservation->setRawAttributes(['id' => 100]);
        $reservation->setRelation('payments', collect([$deposit]));

        $booking = $this->fakeBooking(['id' => 250, 'reservation_id' => 100, 'booking_status' => Booking::STATUS_COMPLETED]);
        $booking->setRelation('reservation', $reservation);

        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid', 'receipt_number' => 'OR-20260923-000082']);
        $billing->setRelation('payments', collect([$deposit])); // same object, reachable a second way
        $booking->setRelation('billing', $billing);

        $receipts = $this->service()->receiptsList($booking);

        $partialReceipts = array_filter($receipts, fn ($r) => $r['receipt_type'] === 'PARTIAL_RECEIPT');
        $officialReceipts = array_filter($receipts, fn ($r) => $r['receipt_type'] === 'OFFICIAL_RECEIPT');

        $this->assertCount(1, $partialReceipts, 'the same payment must not produce two PARTIAL_RECEIPT entries');
        $this->assertCount(1, $officialReceipts, 'a completed billing must produce exactly one OFFICIAL_RECEIPT entry');
        $this->assertCount(2, $receipts);
    }

    // ---- §4/§5 (final checkpoint): PR/FR receipts freeze their own point-in-time snapshot ----

    /**
     * The exact scenario the final checkpoint calls out: a 50% GCash
     * deposit gets its Partial Receipt, then LATER a Cash checkout
     * payment settles the rest and completes checkout. Re-building the
     * OLD Partial Receipt's payload afterward must still show the
     * numbers as they were AT THAT PAYMENT - never silently rewritten
     * into today's ₱10,000-paid/₱0-remaining live totals.
     */
    public function test_partial_receipt_payload_preserves_its_point_in_time_snapshot_after_later_full_settlement(): void
    {
        $deposit = $this->fakePayment([
            'id' => 1, 'amount_paid' => 5000.0,
            'verified_at' => Carbon::parse('2026-09-20 08:00:00'),
            'receipt_number' => 'PR-20260920-000001',
            'payment_date' => Carbon::parse('2026-09-20 07:45:00'),
        ]);
        $checkoutCash = $this->fakePayment([
            'id' => 2, 'payment_method' => 'cash', 'payment_stage' => 'final',
            'billing_id' => 82, 'amount_paid' => 5000.0, 'verified_at' => null,
            'payment_date' => Carbon::parse('2026-09-23 11:30:00'),
        ]);

        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_COMPLETED]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid', 'receipt_number' => 'OR-20260923-000082']);
        $billing->setRelation('payments', collect([$deposit, $checkoutCash]));
        $booking->setRelation('billing', $billing);

        // Sanity: the booking's LIVE totals really are fully settled now.
        $liveSummary = $this->service()->paymentSummary($booking);
        $this->assertSame(10000.0, $liveSummary['total_amount_paid']);
        $this->assertSame(0.0, $liveSummary['remaining_balance']);

        [$summary, $transactions] = $this->service()->summaryAndHistoryForReceipt($booking, $deposit);

        $this->assertSame(5000.0, $summary['total_amount_paid'], 'must stay ₱5,000, not the live ₱10,000');
        $this->assertSame(5000.0, $summary['remaining_balance'], 'must stay ₱5,000, not the live ₱0');
        $this->assertSame(10000.0, $summary['grand_total']);
        $this->assertSame('PARTIALLY_PAID', $summary['payment_status']);
        // Still correctly reports that an Official Receipt is NOW ALSO
        // available - informational, not part of the frozen money figures.
        $this->assertTrue($summary['official_receipt_available']);
        // History trimmed to "as it stood at that point" - only the
        // deposit itself, not the later checkout cash payment.
        $this->assertCount(1, $transactions);
        $this->assertSame(1, $transactions[0]['id']);
    }

    public function test_official_receipt_payload_shows_live_final_totals_and_complete_history(): void
    {
        $deposit = $this->fakePayment([
            'id' => 1, 'amount_paid' => 5000.0,
            'verified_at' => Carbon::parse('2026-09-20 08:00:00'),
            'receipt_number' => 'PR-20260920-000001',
            'payment_date' => Carbon::parse('2026-09-20 07:45:00'),
        ]);
        $checkoutCash = $this->fakePayment([
            'id' => 2, 'payment_method' => 'cash', 'payment_stage' => 'final',
            'billing_id' => 82, 'amount_paid' => 5000.0, 'verified_at' => null,
            'payment_date' => Carbon::parse('2026-09-23 11:30:00'),
        ]);

        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_COMPLETED]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'paid', 'receipt_number' => 'OR-20260923-000082']);
        $billing->setRelation('payments', collect([$deposit, $checkoutCash]));
        $booking->setRelation('billing', $billing);

        [$summary, $transactions] = $this->service()->summaryAndHistoryForReceipt($booking, null);

        $this->assertSame(10000.0, $summary['total_amount_paid']);
        $this->assertSame(0.0, $summary['remaining_balance']);
        $this->assertSame('PAID', $summary['payment_status']);
        $this->assertCount(2, $transactions, 'the Official Receipt must show the complete history, both payments');
    }

    /**
     * A verified 100% pre-checkout payment (FULL_PAYMENT_RECEIPT) must
     * show its own correct point-in-time snapshot (100% paid, ₱0
     * remaining AT THAT TIME) while its receipt_type stays
     * FULL_PAYMENT_RECEIPT, never OFFICIAL_RECEIPT, even though the
     * numbers happen to look "fully paid" already.
     */
    public function test_full_payment_receipt_payload_is_never_labeled_official(): void
    {
        $fullPayment = $this->fakePayment([
            'id' => 1, 'payment_stage' => 'final', 'amount_paid' => 10000.0,
            'verified_at' => Carbon::parse('2026-09-20 08:00:00'),
            'receipt_number' => 'FR-20260920-000001',
        ]);

        $booking = $this->fakeBooking(['id' => 439, 'booking_status' => Booking::STATUS_CHECKED_IN]);
        $billing = $this->fakeBilling(['id' => 82, 'billing_status' => 'pending', 'receipt_number' => null]);
        $billing->setRelation('payments', collect([$fullPayment]));
        $booking->setRelation('billing', $billing);

        [$summary, ] = $this->service()->summaryAndHistoryForReceipt($booking, $fullPayment);

        // receipt_type itself (FULL_PAYMENT_RECEIPT, never OFFICIAL_RECEIPT)
        // is set directly from Payment::receiptType() by
        // ReceiptService::receiptsList()/findReceiptPayload() - already
        // covered by PaymentReceiptEligibilityTest's "verified full
        // payment before checkout is never a partial receipt" test. This
        // test focuses on the money snapshot specifically.
        $this->assertSame(10000.0, $summary['total_amount_paid']);
        $this->assertSame(0.0, $summary['remaining_balance']);
        $this->assertSame('PAID', $summary['payment_status']);
        $this->assertFalse($summary['official_receipt_available'], 'checkout has not happened yet - billing_status is only "pending"');
    }
}
