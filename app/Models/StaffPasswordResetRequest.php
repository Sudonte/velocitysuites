<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Manager/Receptionist's request for the System Administrator to reset
 * their password - see App\Http\Controllers\Auth\ForgotPasswordController
 * (staff branch of sendResetLink()) for how these are created, and
 * App\Http\Controllers\Admin\PasswordResetRequestController for how an
 * admin approves/rejects them. Approval reuses the existing shared
 * DEFAULT_STAFF_PASSWORD + ForcePasswordChangeController mechanism rather
 * than a separate per-request secret.
 */
class StaffPasswordResetRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'processed_by',
        'processed_at',
        'completed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * A stale, never-processed request - purely a display signal (see
     * config('hotel.staff_password_reset_expiry_hours')), not enforced by
     * a scheduled job (this host's cron isn't reliably wired up - see
     * project notes). An admin can still approve/reject an expired
     * request; the requester just sees "expired" if they check back.
     */
    public function isExpired(): bool
    {
        return $this->status === 'pending'
            && $this->created_at->lt(now()->subHours((int) config('hotel.staff_password_reset_expiry_hours', 48)));
    }
}
