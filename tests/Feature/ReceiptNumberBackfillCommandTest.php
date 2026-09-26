<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Coverage for the receipts:backfill-missing-numbers command added this
 * session - a one-time-safe repair for Payment/Billing rows whose
 * receipt_number stayed NULL because the column didn't exist yet at the
 * moment verify()/checkout-completion tried (and failed) to assign one
 * (see the command's own docblock for the full history). These tests
 * confirm it only ever touches genuinely eligible NULLs, never invents a
 * number outside the real generator, never overwrites an existing one,
 * and is safe to re-run.
 */
class ReceiptNumberBackfillCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_status')->default('COMPLETED_BOOKING');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('billing_status')->default('unpaid');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('receipt_number')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billing_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('payment_status')->default('pending');
            $table->string('payment_stage')->default('deposit');
            $table->timestamp('verified_at')->nullable();
            $table->string('receipt_number')->nullable()->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('billings');
        Schema::dropIfExists('bookings');
        parent::tearDown();
    }

    private function makeEligiblePayment(array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'payment_method' => 'gcash',
            'amount_paid' => 1000,
            'payment_status' => 'completed',
            'payment_stage' => 'deposit',
            'verified_at' => now(),
            'receipt_number' => null,
        ], $overrides));
    }

    public function test_missing_receipt_number_is_generated_for_an_eligible_payment(): void
    {
        $payment = $this->makeEligiblePayment();

        Artisan::call('receipts:backfill-missing-numbers', ['--payment-ids' => (string) $payment->id]);

        $payment->refresh();
        $this->assertNotNull($payment->receipt_number);
        $this->assertStringStartsWith('PR-', $payment->receipt_number);
        $this->assertStringContainsString(str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT), $payment->receipt_number);
    }

    public function test_existing_receipt_number_is_never_overwritten(): void
    {
        // receipt_number is deliberately not mass-assignable on the real
        // model (see Payment::$fillable) - forceFill to seed the
        // "already has a number" precondition, same as the real
        // generator's own internal forceFill() call.
        $payment = $this->makeEligiblePayment();
        $payment->forceFill(['receipt_number' => 'PR-20260101-999999'])->save();

        Artisan::call('receipts:backfill-missing-numbers', ['--payment-ids' => (string) $payment->id]);

        $payment->refresh();
        $this->assertSame('PR-20260101-999999', $payment->receipt_number);
    }

    public function test_ineligible_payment_is_left_null(): void
    {
        // Never verified - qualifiesForNewPreCheckoutReceipt() requires verified_at.
        $payment = $this->makeEligiblePayment(['verified_at' => null]);

        Artisan::call('receipts:backfill-missing-numbers', ['--payment-ids' => (string) $payment->id]);

        $payment->refresh();
        $this->assertNull($payment->receipt_number);
    }

    public function test_generated_numbers_are_unique_across_multiple_payments(): void
    {
        $p1 = $this->makeEligiblePayment();
        $p2 = $this->makeEligiblePayment();

        Artisan::call('receipts:backfill-missing-numbers');

        $p1->refresh();
        $p2->refresh();
        $this->assertNotNull($p1->receipt_number);
        $this->assertNotNull($p2->receipt_number);
        $this->assertNotSame($p1->receipt_number, $p2->receipt_number);
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        $payment = $this->makeEligiblePayment();

        Artisan::call('receipts:backfill-missing-numbers', ['--payment-ids' => (string) $payment->id, '--dry-run' => true]);

        $payment->refresh();
        $this->assertNull($payment->receipt_number);
    }

    public function test_rerun_is_idempotent(): void
    {
        $payment = $this->makeEligiblePayment();

        Artisan::call('receipts:backfill-missing-numbers', ['--payment-ids' => (string) $payment->id]);
        $payment->refresh();
        $firstNumber = $payment->receipt_number;
        $this->assertNotNull($firstNumber);

        // Second run must be a no-op: the row no longer matches the
        // whereNull() scan, and the explicit-id "already set" branch must
        // not touch it either.
        Artisan::call('receipts:backfill-missing-numbers', ['--payment-ids' => (string) $payment->id]);
        $payment->refresh();
        $this->assertSame($firstNumber, $payment->receipt_number);
    }

    public function test_billing_as_of_uses_the_supplied_historical_date_not_today(): void
    {
        $booking = Booking::create(['booking_status' => Booking::STATUS_COMPLETED]);
        $billing = Billing::create([
            'booking_id' => $booking->id,
            'billing_status' => 'paid',
            'total_amount' => 5000,
            'receipt_number' => null,
        ]);

        Artisan::call('receipts:backfill-missing-numbers', [
            '--billing-ids' => (string) $billing->id,
            '--billing-as-of' => "{$billing->id}=2026-09-23 18:26:56",
        ]);

        $billing->refresh();
        $this->assertSame('OR-20260923-' . str_pad((string) $billing->id, 6, '0', STR_PAD_LEFT), $billing->receipt_number);
    }

    public function test_billing_not_yet_payable_is_left_null(): void
    {
        $booking = Booking::create(['booking_status' => 'ACTIVE_BOOKING']);
        $billing = Billing::create([
            'booking_id' => $booking->id,
            'billing_status' => 'partial',
            'total_amount' => 5000,
            'receipt_number' => null,
        ]);

        Artisan::call('receipts:backfill-missing-numbers', ['--billing-ids' => (string) $billing->id]);

        $billing->refresh();
        $this->assertNull($billing->receipt_number);
    }
}
