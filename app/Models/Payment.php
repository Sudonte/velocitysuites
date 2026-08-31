<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * True once a GCash payment has been recorded (payment_status
     * 'completed') but not yet acted on by a receptionist (neither
     * verified nor rejected).
     */
    public function isPendingVerification(): bool
    {
        return $this->payment_status === 'completed' && $this->verified_at === null && $this->rejected_at === null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null || $this->payment_status === 'rejected';
    }
}
