<?php

namespace Tests\Unit;

use App\Models\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for Payment::receiptType()/isPartialReceiptEligible()/
 * isFullPaymentReceiptEligible() (PURE READS - reflect only the stored
 * receipt_number, never compute eligibility or mint anything) and for
 * Payment::ensureReceiptNumber()'s side-effect-free NEGATIVE paths (the
 * WRITE-path method, but every branch that returns null/already-stored
 * does so WITHOUT ever touching DB::transaction()/lockForUpdate() - see
 * each test's own comment for why that's provable here).
 *
 * Deliberately plain PHPUnit\Framework\TestCase (no Laravel app boot, no
 * database). Every Payment instance below is built via a bare instance +
 * setRawAttributes() (see payment()'s own doc) with billing_id left
 * unset/null throughout, so nothing here ever touches the billing()
 * relation (constructing a belongsTo() relation object requires
 * resolving the model's DB connection, even when the FK is null - see the
 * historical note in payment()'s doc).
 *
 * What this file does NOT cover (needs a real database, deferred to the
 * MySQL-backed integration phase per explicit instruction):
 * - ensureReceiptNumber() actually minting and persisting a NEW number
 *   (the one branch that genuinely calls DB::transaction()).
 * - qualifiesForNewPreCheckoutReceipt()'s "billing_id set AND billing
 *   already paid" exclusion branch (touches the billing() relation).
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
            'receipt_number' => null,
            'billing_id' => null,
        ], $attributes));

        return $payment;
    }

    /** Converts a plain date string into an already-instantiated Carbon instance - see payment()'s own doc for why this matters here. */
    private function at(string $dateTime): \Carbon\Carbon
    {
        return \Carbon\Carbon::parse($dateTime);
    }

    // ---- receiptType() / isPartialReceiptEligible() / isFullPaymentReceiptEligible() - PURE READS ----

    public function test_receipt_type_is_null_when_no_receipt_number_stored(): void
    {
        // Looks fully "eligible" (completed, verified, deposit, real
        // amount) but was NEVER actually issued a receipt_number - this
        // must stay null, not be computed on the fly. This is the exact
        // §8 "historical null receipt_number stays null after reads" rule.
        $payment = $this->payment([
            'payment_status' => 'completed',
            'verified_at' => $this->at('2026-09-20 10:00:00'),
            'payment_stage' => 'deposit',
            'amount_paid' => 2000.00,
        ]);

        $this->assertNull($payment->receiptType());
        $this->assertFalse($payment->isPartialReceiptEligible());
        $this->assertFalse($payment->isFullPaymentReceiptEligible());
    }

    public function test_receipt_type_reads_pr_prefix_as_partial(): void
    {
        $payment = $this->payment(['receipt_number' => 'PR-20260920-000501']);

        $this->assertSame('PARTIAL_RECEIPT', $payment->receiptType());
        $this->assertTrue($payment->isPartialReceiptEligible());
        $this->assertFalse($payment->isFullPaymentReceiptEligible());
    }

    public function test_receipt_type_reads_fr_prefix_as_full_payment(): void
    {
        $payment = $this->payment(['receipt_number' => 'FR-20260920-000502']);

        $this->assertSame('FULL_PAYMENT_RECEIPT', $payment->receiptType());
        $this->assertTrue($payment->isFullPaymentReceiptEligible());
        $this->assertFalse($payment->isPartialReceiptEligible());
    }

    public function test_receipt_type_unknown_prefix_is_null(): void
    {
        // Should never happen given ensureReceiptNumber() is the only
        // writer, but must never be silently guessed as either type -
        // e.g. an 'OR-' number (Billing's, never Payment's) or garbage.
        $payment = $this->payment(['receipt_number' => 'OR-20260920-000210']);

        $this->assertNull($payment->receiptType());

        $payment2 = $this->payment(['receipt_number' => 'GARBAGE-VALUE']);
        $this->assertNull($payment2->receiptType());
    }

    // ---- ensureReceiptNumber() - side-effect-free negative paths -------
    //
    // Every assertion below proves "no write was attempted" simply by NOT
    // throwing: a plain PHPUnit\Framework\TestCase has no DB connection
    // resolver at all, so DB::transaction()/lockForUpdate()/save() would
    // throw "Call to a member function connection() on null" immediately
    // if reached. A clean return (even null) is direct proof those calls
    // were never made.

    public function test_ensure_receipt_number_returns_existing_number_without_touching_the_database(): void
    {
        $payment = $this->payment(['receipt_number' => 'PR-20260920-000501']);

        $this->assertSame('PR-20260920-000501', $payment->ensureReceiptNumber());
    }

    public function test_ensure_receipt_number_is_null_for_a_pending_payment_without_touching_the_database(): void
    {
        $payment = $this->payment(['payment_status' => 'pending', 'verified_at' => null]);

        $this->assertNull($payment->ensureReceiptNumber());
    }

    public function test_ensure_receipt_number_is_null_for_a_rejected_payment_without_touching_the_database(): void
    {
        $payment = $this->payment(['payment_status' => 'rejected', 'rejected_at' => $this->at('2026-09-20 10:00:00'), 'verified_at' => null]);

        $this->assertNull($payment->ensureReceiptNumber());
    }

    public function test_ensure_receipt_number_is_null_for_a_failed_payment_without_touching_the_database(): void
    {
        $payment = $this->payment(['payment_status' => 'failed', 'verified_at' => null]);

        $this->assertNull($payment->ensureReceiptNumber());
    }

    public function test_ensure_receipt_number_is_null_for_completed_but_not_yet_verified_without_touching_the_database(): void
    {
        $payment = $this->payment(['payment_status' => 'completed', 'verified_at' => null, 'payment_stage' => 'deposit']);

        $this->assertNull($payment->ensureReceiptNumber());
    }

    public function test_ensure_receipt_number_is_null_for_a_checkout_collected_payment_without_touching_the_database(): void
    {
        // Receptionist\CheckOutController::recordPayment() creates its
        // Payment row already 'completed' but NEVER sets verified_at -
        // this must never mint a standalone pre-checkout receipt.
        $payment = $this->payment([
            'payment_method' => 'cash',
            'payment_status' => 'completed',
            'verified_at' => null,
            'payment_stage' => 'final',
            'billing_id' => 82,
            'amount_paid' => 8000.00,
        ]);

        $this->assertNull($payment->ensureReceiptNumber());
    }

    public function test_ensure_receipt_number_is_null_for_zero_amount_without_touching_the_database(): void
    {
        $payment = $this->payment([
            'payment_status' => 'completed',
            'verified_at' => $this->at('2026-09-20 10:00:00'),
            'payment_stage' => 'deposit',
            'amount_paid' => 0,
        ]);

        $this->assertNull($payment->ensureReceiptNumber());
    }
}
