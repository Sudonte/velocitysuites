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
     * The verifying staff member's name, or null - checks the plain
     * verified_by column BEFORE ever touching the verifier() relation,
     * same reasoning as allPayments()/qualifiesForNewPreCheckoutReceipt()'s
     * identical billing_id guard: merely constructing a belongsTo()
     * relation object requires resolving the model's DB connection, even
     * when the FK is null, so touching $this->verifier at all would be
     * unsafe on a bare/unsaved Payment instance regardless of
     * verified_by's value. ReceiptService::paymentTransactions() must use
     * this instead of $payment->verifier?->full_name directly.
     */
    public function verifierName(): ?string
    {
        return $this->verified_by !== null ? $this->verifier?->full_name : null;
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
     * PURE READ. Which receipt document (if any) this payment ALREADY
     * has issued - derived ENTIRELY from the stored receipt_number's own
     * prefix. Never computes eligibility, never touches any other
     * column, never mints anything. This is the one method every
     * read/display/serialization path (ReceiptService, Api\
     * BookingController/ReservationController/ReceiptController, the
     * Receptionist Payment History partial) must call instead of
     * ensureReceiptNumber() - see that method's own doc for the
     * write-path counterpart, which must ONLY ever be called from an
     * explicit business-event flow (Receptionist\PaymentController::
     * verify()).
     *
     * A historical, still-eligible-in-principle payment whose
     * receipt_number was never minted (e.g. it was verified before this
     * feature shipped) correctly returns null here forever, exactly as
     * it should - see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §8 ("do not
     * silently assign receipt numbers to historical records during
     * reads"). Explicit prefix matching only - an unrecognized/malformed
     * receipt_number (should never happen given ensureReceiptNumber() is
     * the only writer) is reported as null/unsupported, never guessed.
     */
    public function receiptType(): ?string
    {
        if ($this->receipt_number === null) {
            return null;
        }

        return match (true) {
            str_starts_with((string) $this->receipt_number, 'PR-') => 'PARTIAL_RECEIPT',
            str_starts_with((string) $this->receipt_number, 'FR-') => 'FULL_PAYMENT_RECEIPT',
            default => null,
        };
    }

    /**
     * True only when a genuine <100% deposit's Partial Payment Receipt
     * has ALREADY been issued (receipt_number stored) - pure read, see
     * receiptType()'s own doc. Does not mint anything, and does not
     * predict/compute eligibility for a not-yet-issued receipt.
     */
    public function isPartialReceiptEligible(): bool
    {
        return $this->receiptType() === 'PARTIAL_RECEIPT';
    }

    /**
     * True only when a guest-submitted, receptionist-verified 100%
     * pre-checkout payment's own receipt has ALREADY been issued - pure
     * read, see receiptType()'s own doc. Explicitly never the Official
     * (checkout) Receipt and never mislabeled as Partial.
     */
    public function isFullPaymentReceiptEligible(): bool
    {
        return $this->receiptType() === 'FULL_PAYMENT_RECEIPT';
    }

    /**
     * WRITE-PATH ELIGIBILITY ONLY - never call this from a read/display
     * path (see receiptType() for the safe read-only equivalent). Used
     * exclusively by ensureReceiptNumber() to decide whether a NOT-YET-
     * ISSUED payment currently qualifies to have one minted right now, at
     * the moment of an explicit business event (Receptionist\
     * PaymentController::verify()).
     *
     * 'PARTIAL_RECEIPT' for a genuine <100% deposit (payment_stage ===
     * 'deposit' - see Api\PaymentController::store()/Api\BookingController::
     * store(), both of which only ever set 'deposit' for a partial
     * payment_type; a 100%/full payment_type always gets 'final', never
     * 'deposit' - so stage alone already guarantees this case is never
     * 100%, no separate percentage check is needed). 'FULL_PAYMENT_RECEIPT'
     * for a guest-submitted 100% payment made BEFORE checkout
     * (payment_stage === 'final' but receptionist-verified - never a
     * receptionist-recorded CHECKOUT payment, which never sets
     * verified_at at all, so it can never reach this method returning
     * non-null). null otherwise (still unverified/pending, rejected, a
     * ₱0 row, or the booking's billing has already reached 'paid' - once
     * checkout has genuinely settled, no NEW pre-checkout receipt should
     * ever be minted; the Official Receipt takes over from there).
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
    private function qualifiesForNewPreCheckoutReceipt(): ?string
    {
        if ($this->payment_status !== 'completed' || $this->verified_at === null) {
            return null;
        }

        if ((float) $this->amount_paid <= 0) {
            return null;
        }

        if ($this->billing_id !== null && $this->billing?->billing_status === 'paid') {
            return null;
        }

        return $this->payment_stage === 'deposit' ? 'PARTIAL_RECEIPT' : 'FULL_PAYMENT_RECEIPT';
    }

    /**
     * WRITE PATH ONLY. Mints (once) and persists this payment's
     * pre-checkout receipt number, or returns null if it isn't eligible.
     * Must ONLY ever be called from an explicit business-event flow -
     * currently just Receptionist\PaymentController::verify(), inside the
     * same DB transaction as the verified_at/payment_status update.
     * NEVER call this from a read/display/serialization path (ReceiptService,
     * any API controller's show()/index(), any Blade view) - see
     * receiptType() for the side-effect-free equivalent every read path
     * must use instead.
     *
     * Idempotent and collision-safe under concurrency: the number embeds
     * this row's own primary key, so two different payments can never
     * produce the same string, and a row-level lock plus a re-check
     * inside the transaction means two near-simultaneous callers for the
     * SAME payment always agree on the one number that gets persisted
     * (the second caller sees the first's already-committed value and
     * simply returns it) - no race-prone max()+1 counter anywhere in this
     * scheme. The payments.receipt_number UNIQUE constraint (see the
     * add_receipt_number_to_payments_and_billings migration) is
     * defense-in-depth only.
     */
    public function ensureReceiptNumber(): ?string
    {
        if ($this->receipt_number) {
            return $this->receipt_number;
        }

        $type = $this->qualifiesForNewPreCheckoutReceipt();
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
     * qualifiesForNewPreCheckoutReceipt(). Deliberately a plain static
     * function (no DB access) so the format itself is unit-testable
     * without a database - see tests/Unit/ReceiptNumberFormatTest.php.
     */
    public static function formatReceiptNumber(string $prefix, int $id, $date): string
    {
        $date = $date instanceof \DateTimeInterface ? $date : now();

        return $prefix . '-' . $date->format('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
