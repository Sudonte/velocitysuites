<?php

namespace Tests\Unit;

use App\Models\Payment;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for Payment::preCheckoutReceiptType()/
 * isPartialReceiptEligible()/isFullPaymentReceiptEligible() - deliberately
 * plain PHPUnit\Framework\TestCase (no Laravel app boot, no database).
 * Every Payment instance below is built via a bare Payment instance +
 * setRawAttributes() (see payment()'s own doc) with billing_id left
 * unset/null throughout, so isEligibleForFirstIssuance() returns on its
 * own `$this->billing_id === null` check and never touches the billing()
 * relation at all - merely CONSTRUCTING a belongsTo() relation object
 * requires resolving the model's DB connection (confirmed the hard way:
 * it throws even when the FK is null), so this is not just an
 * optimization, it's what makes calling these methods safe on an
 * unsaved/unconnected model outside a booted app. This is exactly the
 * DB-free coverage promised for §21 (Partial Receipt availability) and the
 * review-checkpoint correction that a 100% pre-checkout payment must never
 * be mislabeled "Partial".
 *
 * The one case NOT covered here - a payment that WOULD otherwise qualify,
 * but the booking's Billing has already reached 'paid' (isEligibleForFirstIssuance()'s
 * last branch, only reachable when billing_id is actually set) - needs a
 * real, loaded/queried Billing relation, which requires a database;
 * deferred to the MySQL-backed integration phase per explicit instruction.
 */
class PaymentReceiptEligibilityTest extends TestCase
{
    /**
     * Builds a Payment instance via setRawAttributes() rather than the
     * normal constructor/mass-assignment path - deliberately, not for
     * style. Any value assigned to a 'datetime'-cast attribute
     * (verified_at/rejected_at) through the normal setAttribute() path
     * unconditionally calls fromDateTime() -> getDateFormat(), which falls
     * back to $this->getConnection() whenever no explicit $dateFormat is
     * set on the model - and there is no DB connection at all in a plain
     * PHPUnit\Framework\TestCase run (no Laravel app booted), so that
     * throws immediately. setRawAttributes() bypasses that cast-on-write
     * entirely; passing an already-instantiated Carbon (not a string) for
     * the date fields means the cast-on-READ path (asDateTime()) also
     * short-circuits on its own `instanceof CarbonInterface` check without
     * ever needing a connection. This is a plain Carbon::parse() call, not
     * an Eloquent one - no DB involved anywhere in this file.
     */
    private function payment(array $attributes): Payment
    {
        $payment = new Payment();
        $payment->setRawAttributes(array_merge([
            'payment_method' => 'gcash',
            'amount_paid' => 2000.00,
            'verified_at' => null,
            'rejected_at' => null,
        ], $attributes));

        return $payment;
    }

    /** Converts a plain date string into an already-instantiated Carbon instance - see payment()'s own doc for why this matters here. */
    private function at(string $dateTime): Carbon
    {
        return Carbon::parse($dateTime);
    }

    public function test_pending_payment_is_not_eligible_for_any_receipt(): void
    {
        $payment = $this->payment(['payment_status' => 'pending', 'verified_at' => null]);

        $this->assertNull($payment->preCheckoutReceiptType());
        $this->assertFalse($payment->isPartialReceiptEligible());
        $this->assertFalse($payment->isFullPaymentReceiptEligible());
    }

    public function test_rejected_payment_is_not_eligible_for_any_receipt(): void
    {
        $payment = $this->payment(['payment_status' => 'rejected', 'rejected_at' => $this->at('2026-09-20 10:00:00'), 'verified_at' => null]);

        $this->assertNull($payment->preCheckoutReceiptType());
    }

    public function test_failed_payment_is_not_eligible_for_any_receipt(): void
    {
        $payment = $this->payment(['payment_status' => 'failed', 'verified_at' => null]);

        $this->assertNull($payment->preCheckoutReceiptType());
    }

    public function test_completed_but_not_yet_verified_payment_is_not_eligible(): void
    {
        // Still sitting in the receptionist's verification queue - see
        // Payment::isPendingVerification().
        $payment = $this->payment(['payment_status' => 'completed', 'verified_at' => null, 'payment_stage' => 'deposit']);

        $this->assertNull($payment->preCheckoutReceiptType());
    }

    public function test_zero_amount_payment_is_never_eligible_even_if_verified(): void
    {
        $payment = $this->payment([
            'payment_status' => 'completed',
            'verified_at' => $this->at('2026-09-20 10:00:00'),
            'payment_stage' => 'deposit',
            'amount_paid' => 0,
        ]);

        $this->assertNull($payment->preCheckoutReceiptType());
    }

    public function test_verified_deposit_stage_payment_is_a_partial_receipt(): void
    {
        $payment = $this->payment([
            'payment_status' => 'completed',
            'verified_at' => $this->at('2026-09-20 10:00:00'),
            'payment_stage' => 'deposit',
            'amount_paid' => 2000.00,
        ]);

        $this->assertSame('PARTIAL_RECEIPT', $payment->preCheckoutReceiptType());
        $this->assertTrue($payment->isPartialReceiptEligible());
        $this->assertFalse($payment->isFullPaymentReceiptEligible());
    }

    /**
     * The exact bug the review checkpoint flagged: a verified 100%
     * pre-checkout payment (payment_stage 'final', but guest-submitted and
     * receptionist-verified - never a receptionist-recorded checkout
     * payment, which never sets verified_at at all) must NOT be classified
     * as a Partial Receipt.
     */
    public function test_verified_full_payment_before_checkout_is_never_a_partial_receipt(): void
    {
        $payment = $this->payment([
            'payment_status' => 'completed',
            'verified_at' => $this->at('2026-09-20 10:00:00'),
            'payment_stage' => 'final',
            'amount_paid' => 10000.00,
        ]);

        $this->assertSame('FULL_PAYMENT_RECEIPT', $payment->preCheckoutReceiptType());
        $this->assertFalse($payment->isPartialReceiptEligible());
        $this->assertTrue($payment->isFullPaymentReceiptEligible());
    }

    /**
     * A receptionist-recorded checkout payment (Receptionist\
     * CheckOutController::recordPayment()) is created already 'completed'
     * but NEVER sets verified_at - it must never generate a standalone
     * pre-checkout receipt of either kind, only appear inside the Official
     * Receipt's own transaction history (see ReceiptService::transactionType()).
     */
    public function test_checkout_collected_payment_with_no_verified_at_is_not_eligible(): void
    {
        $payment = $this->payment([
            'payment_method' => 'cash',
            'payment_status' => 'completed',
            'verified_at' => null,
            'payment_stage' => 'final',
            'amount_paid' => 8000.00,
        ]);

        $this->assertNull($payment->preCheckoutReceiptType());
    }

    /** @dataProvider partialDepositPercentageProvider */
    public function test_20_30_40_50_percent_deposits_are_all_partial_receipts(float $percentOfTenThousand): void
    {
        $payment = $this->payment([
            'payment_status' => 'completed',
            'verified_at' => $this->at('2026-09-20 10:00:00'),
            'payment_stage' => 'deposit',
            'amount_paid' => 10000.00 * ($percentOfTenThousand / 100),
        ]);

        $this->assertSame('PARTIAL_RECEIPT', $payment->preCheckoutReceiptType());
    }

    public static function partialDepositPercentageProvider(): array
    {
        return [
            '20 percent' => [20.0],
            '30 percent' => [30.0],
            '40 percent' => [40.0],
            '50 percent' => [50.0],
        ];
    }

    public function test_already_issued_partial_receipt_stays_partial_regardless_of_current_mutable_state(): void
    {
        // receipt_number is deliberately NOT fillable (system-generated
        // only) - set directly, the same way Payment::ensureReceiptNumber()
        // itself does after persisting.
        $payment = $this->payment(['payment_stage' => 'deposit']);
        $payment->receipt_number = Payment::formatReceiptNumber('PR', 501, Carbon::create(2026, 9, 20));

        $this->assertSame('PARTIAL_RECEIPT', $payment->preCheckoutReceiptType());
        $this->assertTrue($payment->isPartialReceiptEligible());
    }

    public function test_already_issued_full_payment_receipt_stays_full_regardless_of_current_mutable_state(): void
    {
        $payment = $this->payment(['payment_stage' => 'final']);
        $payment->receipt_number = Payment::formatReceiptNumber('FR', 502, Carbon::create(2026, 9, 20));

        $this->assertSame('FULL_PAYMENT_RECEIPT', $payment->preCheckoutReceiptType());
        $this->assertTrue($payment->isFullPaymentReceiptEligible());
        $this->assertFalse($payment->isPartialReceiptEligible());
    }

    // ---- Explicit prefix handling (hardening correction) ----------------
    //
    // preCheckoutReceiptType() must use an explicit PR-/FR- match, never
    // an "anything not PR- is FR-" fallback - an unknown/malformed
    // receipt_number must report null/unsupported, not be silently
    // guessed as a Full Payment Receipt.

    public function test_pr_prefix_is_partial_receipt(): void
    {
        $payment = $this->payment([]);
        $payment->receipt_number = 'PR-20260920-000501';

        $this->assertSame('PARTIAL_RECEIPT', $payment->preCheckoutReceiptType());
        $this->assertTrue($payment->isPartialReceiptEligible());
        $this->assertFalse($payment->isFullPaymentReceiptEligible());
    }

    public function test_fr_prefix_is_full_payment_receipt(): void
    {
        $payment = $this->payment([]);
        $payment->receipt_number = 'FR-20260920-000502';

        $this->assertSame('FULL_PAYMENT_RECEIPT', $payment->preCheckoutReceiptType());
        $this->assertTrue($payment->isFullPaymentReceiptEligible());
        $this->assertFalse($payment->isPartialReceiptEligible());
    }

    public function test_unknown_prefix_is_null_not_full_payment_receipt(): void
    {
        // Should never happen given ensureReceiptNumber() is the only
        // writer of this column, but a defensive guarantee: an
        // unrecognized prefix (or an 'OR-' Official Receipt number, which
        // belongs to Billing, never Payment) must never be silently
        // treated as a Full Payment Receipt just because it isn't 'PR-'.
        $payment = $this->payment([]);
        $payment->receipt_number = 'OR-20260920-000210';

        $this->assertNull($payment->preCheckoutReceiptType());
        $this->assertFalse($payment->isPartialReceiptEligible());
        $this->assertFalse($payment->isFullPaymentReceiptEligible());
    }

    public function test_malformed_prefix_is_null_not_full_payment_receipt(): void
    {
        $payment = $this->payment([]);
        $payment->receipt_number = 'GARBAGE-VALUE';

        $this->assertNull($payment->preCheckoutReceiptType());
        $this->assertFalse($payment->isPartialReceiptEligible());
        $this->assertFalse($payment->isFullPaymentReceiptEligible());
    }
}
