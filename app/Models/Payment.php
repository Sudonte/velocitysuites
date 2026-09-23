<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_id',
        'reservation_id',
        'booking_id',
        'payment_method',
        'reference_number',
        'receipt_path',
        'gcash_number',
        'amount_paid',
        'payment_status',
        'payment_stage',
        'verified_by',
        'verified_at',
        'rejection_reason',
        'rejected_at',
        'rejected_by',
        'payment_date',
        // receipt_number is deliberately NOT fillable - it's a system-
        // generated, lazily-assigned identifier (see ensureReceiptNumber()
        // below), never something a request body should be able to set.
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Computed, ready-to-use attributes for API consumers (the mobile
     * app) - same convention as Guest::profile_picture_url. receipt_url
     * is a full absolute URL for the stored receipt_path (rather than a
     * bare storage-relative path); verification_status is a single
     * authoritative string derived from verified_at/rejected_at/
     * payment_status so clients don't have to re-derive it themselves.
     */
    protected $appends = [
        'receipt_url',
        'verification_status',
    ];

    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_path ? Storage::disk('public')->url($this->receipt_path) : null;
    }

    /**
     * One of: null (no receipt/verification applicable yet),
     * 'pending_verification', 'verified', 'rejected'.
     */
    public function getVerificationStatusAttribute(): ?string
    {
        if ($this->isRejected()) {
            return 'rejected';
        }

        if ($this->isVerified()) {
            return 'verified';
        }

        if ($this->isPendingVerification()) {
            return 'pending_verification';
        }

        return null;
    }

    /**
     * Get the billing associated with the payment. Null for a deposit
     * payment made at reservation time, before a Billing exists - it gets
     * re-parented onto one at checkout.
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * Get the reservation this deposit payment was made against. Only set
     * for a reservation-derived transaction's deposit stage.
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the direct Booking this payment was made against - only set for
     * a "New Booking" transaction's payment (reservation_id null). Mirrors
     * reservation() above.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the staff member who verified this payment (GCash receipt
     * verification, etc.).
     */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Get the staff member who rejected this payment (bad/mismatched
     * GCash receipt, etc.).
     */
    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * True while a guest-submitted GCash payment is still awaiting
     * resolution and hasn't been acted on by a receptionist (neither
     * verified nor rejected) - covers both the 'completed' case (a
     * booking-stage payment sitting in a receptionist's review queue) AND
     * the raw 'pending' case (a reservation-stage deposit/final GCash
     * payment whose ReservationWorkflowService::tryAutoConvert() attempt
     * never actually completed it - e.g. the room type was momentarily
     * fully booked). Without 'pending' here, a guest whose auto-convert
     * got skipped could never cancel/void the stuck submission themselves
     * (Guest\PaymentController/Api\PaymentController's cancel()/void()),
     * since it would never reach 'completed' on its own.
     */
    public function isPendingVerification(): bool
    {
        return $this->payment_method === 'gcash'
            && in_array($this->payment_status, ['pending', 'completed'], true)
            && $this->verified_at === null
            && $this->rejected_at === null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null || $this->payment_status === 'rejected';
    }

    /**
     * Which kind of PRE-CHECKOUT receipt (if any) this specific payment
     * currently qualifies for:
     *
     * - 'PARTIAL_RECEIPT' for a genuine <100% deposit (payment_stage ===
     *   'deposit' - see Api\PaymentController::store()/Api\BookingController::
     *   store(), both of which only ever set 'deposit' for a partial
     *   payment_type; a 100%/full payment_type always gets 'final', never
     *   'deposit' - so stage alone already guarantees this case is never
     *   100%, no separate percentage check is needed).
     * - 'FULL_PAYMENT_RECEIPT' for a guest-submitted 100% payment made
     *   BEFORE checkout (payment_stage === 'final' but receptionist-
     *   verified, i.e. NOT a checkout-collected payment - see below).
     *   Deliberately never labeled 'PARTIAL_RECEIPT' (would misrepresent a
     *   full payment as partial) and deliberately never labeled
     *   'OFFICIAL_RECEIPT' either (PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md
     *   §31 Scenario C: "the Official Checkout Receipt must still not be
     *   released until final checkout is completed" - a pre-checkout 100%
     *   payment, however fully verified, is not that).
     * - null if this payment doesn't qualify for either (still
     *   unverified/pending, rejected, a ₱0 row, or - critically - a
     *   receptionist-recorded CHECKOUT payment: Receptionist\
     *   CheckOutController::recordPayment() always creates its Payment row
     *   already 'completed' but with verified_at permanently null, so
     *   isEligibleForFirstIssuance() below already excludes it; it only
     *   ever appears inside the Official Receipt's own payment
     *   transaction history, never as a standalone receipt of its own).
     *
     * Checked in "already issued" order first: once a receipt_number
     * exists, this keeps returning whichever type it was originally
     * issued as, forever - even after the booking's checkout later
     * completes - see isPartialReceiptEligible()'s own §5 reference. The
     * type is sniffed from the number's own prefix ('PR-'/'FR-') rather
     * than a separate stored column, since the type can never change
     * after issuance (payment_stage is immutable) and this avoids another
     * schema migration for a value derivable from data already on hand.
     */
    public function preCheckoutReceiptType(): ?string
    {
        if ($this->receipt_number !== null) {
            // Explicit prefix handling only - an unrecognized/malformed
            // receipt_number (should never happen given ensureReceiptNumber()
            // is the only writer, but this must never silently default to
            // treating "anything not PR-" as a Full Payment Receipt) is
            // reported as null/unsupported rather than guessed.
            return match (true) {
                str_starts_with((string) $this->receipt_number, 'PR-') => 'PARTIAL_RECEIPT',
                str_starts_with((string) $this->receipt_number, 'FR-') => 'FULL_PAYMENT_RECEIPT',
                default => null,
            };
        }

        if (!$this->isEligibleForFirstIssuance()) {
            return null;
        }

        return $this->payment_stage === 'deposit' ? 'PARTIAL_RECEIPT' : 'FULL_PAYMENT_RECEIPT';
    }

    /**
     * True only for a genuine <100% deposit's Partial Payment Receipt -
     * see preCheckoutReceiptType()'s own doc for exactly why a 100%
     * pre-checkout payment can never satisfy this.
     */
    public function isPartialReceiptEligible(): bool
    {
        return $this->preCheckoutReceiptType() === 'PARTIAL_RECEIPT';
    }

    /**
     * True for a guest-submitted, receptionist-verified 100% payment made
     * before checkout - a real, verified payment receipt, but explicitly
     * NOT the Official (checkout) Receipt and NOT mislabeled as a Partial
     * one. See preCheckoutReceiptType()'s own doc.
     */
    public function isFullPaymentReceiptEligible(): bool
    {
        return $this->preCheckoutReceiptType() === 'FULL_PAYMENT_RECEIPT';
    }

    /**
     * The actual eligibility test shared by both pre-checkout receipt
     * types above - a receptionist-verified, completed, non-zero payment,
     * made before the booking's billing (if any exists yet) has already
     * reached 'paid'. A receptionist-recorded checkout payment
     * (Receptionist\CheckOutController::recordPayment()) never sets
     * verified_at, so it can never pass this check - it only ever appears
     * inside the Official Receipt's own payment transaction history.
     *
     * Checks the plain billing_id column BEFORE ever touching the
     * billing() relation - not just an optimization (skips a lazy-load
     * query entirely for the common case, which is every deposit-stage
     * payment made before a Billing exists at all), but also what makes
     * this method safely callable on a bare, unsaved/unconnected Payment
     * instance (see tests/Unit/PaymentReceiptEligibilityTest.php) -
     * merely constructing a belongsTo() relation object requires
     * resolving the model's DB connection, even when the FK is null, so
     * touching $this->billing at all would be unsafe outside a booted app
     * regardless of billing_id's value.
     */
    private function isEligibleForFirstIssuance(): bool
    {
        if ($this->payment_status !== 'completed' || $this->verified_at === null) {
            return false;
        }

        if ((float) $this->amount_paid <= 0) {
            return false;
        }

        if ($this->billing_id === null) {
            return true;
        }

        return $this->billing?->billing_status !== 'paid';
    }

    /**
     * Lazily assigns (once) and returns this payment's pre-checkout
     * receipt number (PR-.../FR-... per preCheckoutReceiptType()), or
     * null if it isn't eligible for one. Idempotent and collision-safe
     * under concurrency: the number embeds this row's own primary key, so
     * two different payments can never produce the same string, and a
     * row-level lock plus a re-check inside the transaction means two
     * near-simultaneous callers for the SAME payment always agree on the
     * one number that gets persisted (the second caller sees the first's
     * already-committed value and simply returns it) - no race-prone
     * max()+1 counter anywhere in this scheme. The payments.receipt_number
     * UNIQUE constraint (see the add_receipt_number_to_payments_and_billings
     * migration) is defense-in-depth only.
     */
    public function ensureReceiptNumber(): ?string
    {
        if ($this->receipt_number) {
            return $this->receipt_number;
        }

        $type = $this->preCheckoutReceiptType();
        if ($type === null) {
            return null;
        }

        $prefix = $type === 'PARTIAL_RECEIPT' ? 'PR' : 'FR';

        return DB::transaction(function () use ($prefix) {
            $locked = static::whereKey($this->id)->lockForUpdate()->first();
            if (!$locked) {
                return null;
            }

            if ($locked->receipt_number) {
                $this->receipt_number = $locked->receipt_number;

                return $locked->receipt_number;
            }

            $number = static::formatReceiptNumber($prefix, $locked->id, $locked->verified_at ?? now());
            $locked->forceFill(['receipt_number' => $number])->save();
            $this->receipt_number = $number;

            return $number;
        });
    }

    /**
     * Pure formatting - {prefix}-{issued date}-{zero-padded payment id}.
     * $prefix is 'PR' (Partial) or 'FR' (Full Payment, pre-checkout) - see
     * preCheckoutReceiptType(). Deliberately a plain static function (no
     * DB access) so the format itself is unit-testable without a
     * database - see tests/Unit/ReceiptNumberFormatTest.php.
     */
    public static function formatReceiptNumber(string $prefix, int $id, $date): string
    {
        $date = $date instanceof \DateTimeInterface ? $date : now();

        return $prefix . '-' . $date->format('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
