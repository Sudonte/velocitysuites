<?php

namespace Tests\Unit;

use App\Models\Billing;
use App\Models\Payment;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the receipt-number FORMAT itself - deliberately
 * plain PHPUnit\Framework\TestCase (no Laravel app boot, no database).
 * Payment::formatReceiptNumber()/Billing::formatReceiptNumber() are plain
 * static functions with no DB access, so the format's collision-freedom
 * (every number embeds its own row's primary key) can be proven without
 * ever persisting anything - see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md
 * §15/§16 (receipt-number generation, collision protection).
 *
 * The DB-backed half of this feature - Payment::ensureReceiptNumber()/
 * Billing::ensureOfficialReceiptNumber()'s lock-and-recheck idempotency,
 * and the payments/billings.receipt_number UNIQUE constraint itself - is
 * NOT covered here; see the accompanying report's "Testing performed"
 * section for why (this repo's own test database bootstrap currently
 * can't run a fresh migration set at all, for reasons unrelated to this
 * feature - deferred to the MySQL-backed integration phase per explicit
 * instruction, not fixed here).
 */
class ReceiptNumberFormatTest extends TestCase
{
    public function test_partial_receipt_number_format(): void
    {
        $number = Payment::formatReceiptNumber('PR', 123, Carbon::create(2026, 9, 20));

        $this->assertSame('PR-20260920-000123', $number);
    }

    public function test_full_payment_receipt_number_format(): void
    {
        $number = Payment::formatReceiptNumber('FR', 123, Carbon::create(2026, 9, 20));

        $this->assertSame('FR-20260920-000123', $number);
    }

    public function test_official_receipt_number_format(): void
    {
        $number = Billing::formatReceiptNumber(45, Carbon::create(2026, 9, 23));

        $this->assertSame('OR-20260923-000045', $number);
    }

    public function test_all_three_prefixes_can_never_collide_for_the_same_id_and_date(): void
    {
        // Same id, same date, three different row/receipt types - the
        // 'PR-'/'FR-'/'OR-' prefix alone guarantees these never collide,
        // which is also how ReceiptService::findReceiptPayload() dispatches
        // a lookup to the right table/type.
        $partial = Payment::formatReceiptNumber('PR', 7, Carbon::create(2026, 9, 20));
        $full = Payment::formatReceiptNumber('FR', 7, Carbon::create(2026, 9, 20));
        $official = Billing::formatReceiptNumber(7, Carbon::create(2026, 9, 20));

        $this->assertCount(3, array_unique([$partial, $full, $official]));
        $this->assertStringStartsWith('PR-', $partial);
        $this->assertStringStartsWith('FR-', $full);
        $this->assertStringStartsWith('OR-', $official);
    }

    public function test_different_ids_never_collide_regardless_of_date(): void
    {
        $numbers = [];
        for ($id = 1; $id <= 500; $id++) {
            $numbers[] = Payment::formatReceiptNumber('PR', $id, Carbon::create(2026, 9, 20));
        }

        $this->assertCount(500, array_unique($numbers));
    }

    public function test_id_is_zero_padded_to_six_digits(): void
    {
        $this->assertSame('PR-20260920-000001', Payment::formatReceiptNumber('PR', 1, Carbon::create(2026, 9, 20)));
        // An id with more than 6 digits is never truncated - str_pad only
        // pads up TO a minimum length, it never cuts a longer string down.
        $this->assertSame('PR-20260920-1234567', Payment::formatReceiptNumber('PR', 1234567, Carbon::create(2026, 9, 20)));
    }
}
