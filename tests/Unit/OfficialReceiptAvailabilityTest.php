<?php

namespace Tests\Unit;

use App\Models\Billing;
use App\Models\Booking;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for Billing::isOfficialReceiptAvailable() - deliberately
 * plain PHPUnit\Framework\TestCase (no Laravel app boot, no database).
 * Every Billing/Booking instance below is constructed in-memory (never
 * saved), and the belongsTo `booking` relation is wired via
 * Eloquent's own setRelation() - this pre-populates the relation cache
 * directly, so `$billing->booking` returns the given instance WITHOUT
 * ever issuing a query. This is exactly the DB-free coverage the review
 * checkpoint asked for on the corrected checkout-completion-aware
 * predicate (§20/§3): "billing paid without completed checkout is NOT an
 * Official Receipt" and "completed checkout + fully paid billing IS an
 * Official Receipt" are both real, confirmed behaviors here, not just
 * asserted.
 */
class OfficialReceiptAvailabilityTest extends TestCase
{
    private function billingWithBooking(string $billingStatus, ?string $bookingStatus): Billing
    {
        $billing = new Billing(['billing_status' => $billingStatus]);

        if ($bookingStatus !== null) {
            $booking = new Booking(['booking_status' => $bookingStatus]);
            $billing->setRelation('booking', $booking);
        } else {
            $billing->setRelation('booking', null);
        }

        return $billing;
    }

    public function test_completed_checkout_and_fully_paid_billing_is_official_receipt_available(): void
    {
        $billing = $this->billingWithBooking('paid', Booking::STATUS_COMPLETED);

        $this->assertTrue($billing->isOfficialReceiptAvailable());
    }

    /**
     * The exact bug found during the backend review: Receptionist\
     * CheckOutController::refreshStayCharges() recomputes billing_status
     * to 'paid' purely from the paid-vs-total sum every time the Billing
     * Panel is merely OPENED - before the receptionist has done anything
     * in the Payment Panel, and before booking_status has moved off
     * Booking::STATUS_CHECKED_IN. billing_status alone must never be
     * enough to expose the Official Receipt.
     */
    public function test_billing_paid_but_checkout_not_actually_completed_is_not_official_receipt_available(): void
    {
        $billing = $this->billingWithBooking('paid', Booking::STATUS_CHECKED_IN);

        $this->assertFalse($billing->isOfficialReceiptAvailable());
    }

    public function test_billing_not_paid_is_never_official_receipt_available_even_if_checked_out(): void
    {
        $billing = $this->billingWithBooking('partial', Booking::STATUS_COMPLETED);

        $this->assertFalse($billing->isOfficialReceiptAvailable());
    }

    public function test_pending_billing_is_not_official_receipt_available(): void
    {
        $billing = $this->billingWithBooking('pending', Booking::STATUS_CHECKED_IN);

        $this->assertFalse($billing->isOfficialReceiptAvailable());
    }

    public function test_billing_with_no_booking_at_all_is_not_official_receipt_available(): void
    {
        $billing = $this->billingWithBooking('paid', null);

        $this->assertFalse($billing->isOfficialReceiptAvailable());
    }

    public function test_a_cancelled_booking_can_never_show_an_official_receipt_even_if_billing_reads_paid(): void
    {
        $billing = $this->billingWithBooking('paid', Booking::STATUS_CANCELLED);

        $this->assertFalse($billing->isOfficialReceiptAvailable());
    }
}
