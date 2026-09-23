<?php

namespace Tests\Unit;

use App\Support\PaymentMath;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the payment/receipt arithmetic every guest/
 * receptionist-facing surface relies on - deliberately plain
 * PHPUnit\Framework\TestCase (no Laravel app boot, no database) since
 * PaymentMath itself never touches Eloquent or the DB. See
 * PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md items #6-8 (Total Amount Paid /
 * Remaining Balance / Payment Percentage correctness) and the review-
 * checkpoint corrections (merge/dedup, chronological order).
 */
class PaymentMathTest extends TestCase
{
    // ---- totalPaid() - status inclusion/exclusion -------------------

    public function test_total_paid_only_counts_completed_payments(): void
    {
        $payments = [
            ['payment_status' => 'completed', 'amount_paid' => '2000.00'],
            ['payment_status' => 'pending', 'amount_paid' => '3000.00'],
            ['payment_status' => 'rejected', 'amount_paid' => '3000.00'],
            ['payment_status' => 'failed', 'amount_paid' => '3000.00'],
            ['payment_status' => 'completed', 'amount_paid' => '3000.00'],
        ];

        $this->assertSame(5000.0, PaymentMath::totalPaid($payments));
    }

    public function test_total_paid_excludes_a_pending_payment(): void
    {
        $this->assertSame(0.0, PaymentMath::totalPaid([['payment_status' => 'pending', 'amount_paid' => '2000.00']]));
    }

    public function test_total_paid_excludes_a_rejected_payment(): void
    {
        $this->assertSame(0.0, PaymentMath::totalPaid([['payment_status' => 'rejected', 'amount_paid' => '2000.00']]));
    }

    public function test_total_paid_excludes_a_failed_payment(): void
    {
        // A guest's own cancel()/void() of an in-flight GCash attempt both
        // set payment_status='failed' too - there is no separate
        // 'cancelled'/'voided' status value in this schema (confirmed
        // against Api\PaymentController::cancel()/void()).
        $this->assertSame(0.0, PaymentMath::totalPaid([['payment_status' => 'failed', 'amount_paid' => '2000.00']]));
    }

    public function test_total_paid_includes_a_completed_gcash_deposit(): void
    {
        $this->assertSame(2000.0, PaymentMath::totalPaid([
            ['payment_status' => 'completed', 'amount_paid' => '2000.00', 'payment_method' => 'gcash'],
        ]));
    }

    public function test_total_paid_includes_a_completed_checkout_cash_payment_with_no_verified_at(): void
    {
        // Receptionist\CheckOutController::recordPayment() creates its Payment
        // row already 'completed' with verified_at permanently null (no
        // separate verification step for a checkout collection) - it must
        // still count as paid.
        $this->assertSame(5000.0, PaymentMath::totalPaid([
            ['payment_status' => 'completed', 'amount_paid' => '5000.00', 'payment_method' => 'cash', 'verified_at' => null],
        ]));
    }

    public function test_total_paid_of_empty_list_is_zero(): void
    {
        $this->assertSame(0.0, PaymentMath::totalPaid([]));
    }

    // ---- remainingBalance() / paymentStatus() ------------------------

    public function test_remaining_balance_never_goes_negative(): void
    {
        $this->assertSame(0.0, PaymentMath::remainingBalance(10000.0, 12000.0));
        $this->assertSame(0.0, PaymentMath::remainingBalance(10000.0, 10000.0));
        $this->assertSame(5000.0, PaymentMath::remainingBalance(10000.0, 5000.0));
    }

    public function test_payment_status_pending_when_nothing_paid(): void
    {
        $this->assertSame('PENDING', PaymentMath::paymentStatus(10000.0, 0.0));
    }

    /** @dataProvider partialPercentageProvider */
    public function test_payment_status_partially_paid_for_each_qualifying_deposit_tier(float $percentage): void
    {
        $grandTotal = 10000.0;
        $totalPaid = $grandTotal * ($percentage / 100);

        $this->assertSame('PARTIALLY_PAID', PaymentMath::paymentStatus($grandTotal, $totalPaid));
    }

    public static function partialPercentageProvider(): array
    {
        return [
            '20 percent' => [20.0],
            '30 percent' => [30.0],
            '40 percent' => [40.0],
            '50 percent' => [50.0],
        ];
    }

    public function test_payment_status_paid_at_full_amount(): void
    {
        $this->assertSame('PAID', PaymentMath::paymentStatus(10000.0, 10000.0));
        // Overpaid-by-rounding-noise still reads as fully PAID, never a
        // negative-remaining-balance state.
        $this->assertSame('PAID', PaymentMath::paymentStatus(10000.0, 10000.004));
    }

    /**
     * Scenario A/B from PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md - a 20%
     * GCash deposit followed by a Cash remaining-balance checkout payment
     * must sum to exactly the Grand Total, never overstate/understate it.
     */
    public function test_scenario_a_20_percent_gcash_then_cash_remaining_balance(): void
    {
        $grandTotal = 10000.0;
        $payments = [
            ['payment_status' => 'completed', 'amount_paid' => '2000.00'],
            ['payment_status' => 'completed', 'amount_paid' => '8000.00'],
        ];

        $totalPaid = PaymentMath::totalPaid($payments);

        $this->assertSame(10000.0, $totalPaid);
        $this->assertSame(0.0, PaymentMath::remainingBalance($grandTotal, $totalPaid));
        $this->assertSame('PAID', PaymentMath::paymentStatus($grandTotal, $totalPaid));
    }

    /**
     * A rejected GCash payment must never count toward Total Amount Paid,
     * and Remaining Balance must stay exactly the full Grand Total - see
     * PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md Scenario E.
     */
    public function test_scenario_e_rejected_payment_never_counts_as_paid(): void
    {
        $grandTotal = 10000.0;
        $payments = [
            ['payment_status' => 'rejected', 'amount_paid' => '2000.00'],
        ];

        $totalPaid = PaymentMath::totalPaid($payments);

        $this->assertSame(0.0, $totalPaid);
        $this->assertSame(10000.0, PaymentMath::remainingBalance($grandTotal, $totalPaid));
        $this->assertSame('PENDING', PaymentMath::paymentStatus($grandTotal, $totalPaid));
    }

    // ---- normalizePercentage() ----------------------------------------

    public function test_normalize_percentage_does_not_double_multiply_a_whole_value(): void
    {
        $this->assertSame(50, PaymentMath::normalizePercentage(50.0));
        $this->assertSame(20, PaymentMath::normalizePercentage(20.0));
        $this->assertSame(100, PaymentMath::normalizePercentage(100.0));
    }

    public function test_normalize_percentage_converts_a_fraction_exactly_once(): void
    {
        $this->assertSame(50, PaymentMath::normalizePercentage(0.50));
        $this->assertSame(20, PaymentMath::normalizePercentage(0.20));
    }

    public function test_normalize_percentage_null_stays_null_not_zero(): void
    {
        $this->assertNull(PaymentMath::normalizePercentage(null));
    }

    // ---- mergeAndDeduplicate() ------------------------------------------

    public function test_merge_and_deduplicate_keeps_first_occurrence_of_a_repeated_id(): void
    {
        // Mirrors Booking::allPayments() merging reservation-scoped,
        // booking-scoped, and billing-scoped sources where a deposit
        // payment re-parented onto the billing (Receptionist\
        // CheckOutController::refreshStayCharges()) still carries its
        // original reservation_id, so it legitimately appears in two
        // sources.
        $reservationPayments = [['id' => 1, 'amount_paid' => '2000.00'], ['id' => 2, 'amount_paid' => '500.00']];
        $bookingPayments = [];
        $billingPayments = [['id' => 1, 'amount_paid' => '2000.00'], ['id' => 3, 'amount_paid' => '8000.00']];

        $merged = PaymentMath::mergeAndDeduplicate($reservationPayments, $bookingPayments, $billingPayments);

        $this->assertCount(3, $merged);
        $this->assertSame([1, 2, 3], array_column($merged, 'id'));
    }

    public function test_merge_and_deduplicate_never_inflates_total_amount_paid(): void
    {
        $reservationPayments = [['id' => 1, 'payment_status' => 'completed', 'amount_paid' => '2000.00']];
        $billingPayments = [
            ['id' => 1, 'payment_status' => 'completed', 'amount_paid' => '2000.00'], // same payment, re-parented
            ['id' => 2, 'payment_status' => 'completed', 'amount_paid' => '8000.00'],
        ];

        $merged = PaymentMath::mergeAndDeduplicate($reservationPayments, [], $billingPayments);

        // Not ₱12,000 - the repeated id must count exactly once.
        $this->assertSame(10000.0, PaymentMath::totalPaid($merged));
    }

    public function test_merge_and_deduplicate_keeps_items_with_no_id(): void
    {
        $merged = PaymentMath::mergeAndDeduplicate([['amount_paid' => '100.00']], [['amount_paid' => '200.00']]);

        $this->assertCount(2, $merged);
    }

    // ---- sortChronologically() ------------------------------------------

    public function test_sort_chronologically_orders_by_payment_date_ascending(): void
    {
        $payments = [
            ['id' => 2, 'payment_date' => new \DateTimeImmutable('2026-09-23')],
            ['id' => 1, 'payment_date' => new \DateTimeImmutable('2026-09-20')],
        ];

        $sorted = PaymentMath::sortChronologically($payments);

        $this->assertSame([1, 2], array_column($sorted, 'id'));
    }

    public function test_sort_chronologically_falls_back_to_created_at_when_payment_date_missing(): void
    {
        $payments = [
            ['id' => 2, 'created_at' => new \DateTimeImmutable('2026-09-23')],
            ['id' => 1, 'created_at' => new \DateTimeImmutable('2026-09-20')],
        ];

        $sorted = PaymentMath::sortChronologically($payments);

        $this->assertSame([1, 2], array_column($sorted, 'id'));
    }

    public function test_sort_chronologically_breaks_ties_by_id_ascending(): void
    {
        $sameInstant = new \DateTimeImmutable('2026-09-20 10:00:00');
        $payments = [
            ['id' => 5, 'payment_date' => $sameInstant],
            ['id' => 2, 'payment_date' => $sameInstant],
            ['id' => 3, 'payment_date' => $sameInstant],
        ];

        $sorted = PaymentMath::sortChronologically($payments);

        $this->assertSame([2, 3, 5], array_column($sorted, 'id'));
    }

    public function test_sort_chronologically_is_not_dependent_on_input_order(): void
    {
        $payments = [
            ['id' => 3, 'payment_date' => new \DateTimeImmutable('2026-09-25')],
            ['id' => 1, 'payment_date' => new \DateTimeImmutable('2026-09-20')],
            ['id' => 2, 'payment_date' => new \DateTimeImmutable('2026-09-22')],
        ];

        $sorted = PaymentMath::sortChronologically($payments);

        $this->assertSame([1, 2, 3], array_column($sorted, 'id'));
    }
}
