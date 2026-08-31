<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'password',
        'role',
        'status',
        'failed_login_attempts',
        'last_login_at',
        'email_verified_at',
        'deleted_at',
        'restore_deadline',
        'age',
        'gender',
        'date_of_birth',
        'mobile_number',
        'country',
        'region',
        'province',
        'city',
        'barangay',
        'street',
        'zip_code',
        'timezone',
        'profile_picture',
        'profile_picture_changed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Appended so API/Blade consumers get a ready-to-use URL instead of the bare storage path. */
    protected $appends = [
        'profile_picture_url',
        'initials',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
            'restore_deadline' => 'datetime',
            'date_of_birth' => 'date',
            'profile_picture_changed_at' => 'datetime',
        ];
    }

    /**
     * Same pattern as Guest::getProfilePictureUrlAttribute() - absolute URL for the stored
     * path. Staff/admin accounts store their picture directly on `users.profile_picture`,
     * but guests store it on the linked `guests` row instead (same one the mobile API
     * reads/writes) - fall back there so a guest's picture actually shows up on web too.
     */
    public function getProfilePictureUrlAttribute(): ?string
    {
        if ($this->profile_picture) {
            return Storage::disk('public')->url($this->profile_picture);
        }

        if ($this->role === 'guest' && $this->guest && $this->guest->profile_picture) {
            return Storage::disk('public')->url($this->guest->profile_picture);
        }

        return null;
    }

    /**
     * Two-letter avatar placeholder (first-name initial + last-name initial, e.g. "Juan
     * Dela Cruz" -> "JC") - used wherever no profile picture is available instead of a
     * generic icon. Always derived from the real account data, never hardcoded.
     */
    public function getInitialsAttribute(): string
    {
        $initials = mb_strtoupper(mb_substr((string) $this->first_name, 0, 1) . mb_substr((string) $this->last_name, 0, 1));

        return $initials !== '' ? $initials : 'U';
    }

    /** True if the picture has never been changed, or the last change was over a month ago. */
    public function canChangeProfilePicture(): bool
    {
        return $this->profile_picture_changed_at === null
            || $this->profile_picture_changed_at->addMonth()->isPast();
    }

    /** Null once changeable again; otherwise the date the one-month cooldown lifts. */
    public function nextProfilePictureChangeDate(): ?Carbon
    {
        if ($this->canChangeProfilePicture()) {
            return null;
        }

        return $this->profile_picture_changed_at->copy()->addMonth();
    }

    /**
     * Human-friendly role label for display (sidebar/header/profile pages) - not appended
     * to JSON serialization since the mobile API/admin tooling expect the raw `role` enum
     * value, only used directly in Blade via $user->role_label.
     */
    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            'admin' => 'System Administrator',
            'manager' => 'Manager',
            'receptionist' => 'Receptionist',
            'guest' => 'Guest/User',
            default => ucfirst((string) $this->role),
        };
    }

    /**
     * Get the user's full name.
     */
    public function getFullNameAttribute(): string
    {
        if ($this->middle_name) {
            return "{$this->first_name} {$this->middle_name} {$this->last_name}";
        }
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the user's display name (Last, First M.).
     */
    public function getDisplayNameAttribute(): string
    {
        $name = "{$this->last_name}, {$this->first_name}";
        if ($this->middle_name) {
            $name .= ' ' . strtoupper(substr($this->middle_name, 0, 1)) . '.';
        }
        return $name;
    }

    /**
     * Set the user's first name.
     */
    public function setFirstNameAttribute(string $value): void
    {
        $this->attributes['first_name'] = ucwords(strtolower($value));
    }

    /**
     * Set the user's last name.
     */
    public function setLastNameAttribute(string $value): void
    {
        $this->attributes['last_name'] = ucwords(strtolower($value));
    }

    /**
     * Set the user's middle name.
     */
    public function setMiddleNameAttribute(?string $value): void
    {
        $this->attributes['middle_name'] = $value ? ucwords(strtolower($value)) : null;
    }

    /**
     * Set the user's full name from a single string.
     * Useful for backwards compatibility.
     */
    public function setFullNameAttribute(string $value): void
    {
        $parts = explode(' ', trim($value));
        $count = count($parts);

        if ($count === 1) {
            $this->attributes['first_name'] = $parts[0];
            $this->attributes['last_name'] = '';
            $this->attributes['middle_name'] = null;
        } elseif ($count === 2) {
            $this->attributes['first_name'] = $parts[0];
            $this->attributes['last_name'] = $parts[1];
            $this->attributes['middle_name'] = null;
        } else {
            // First name is first part, last name is last part, middle name is everything else
            $this->attributes['first_name'] = $parts[0];
            $this->attributes['last_name'] = $parts[$count - 1];
            $this->attributes['middle_name'] = implode(' ', array_slice($parts, 1, $count - 2));
        }
    }

    /**
     * Scope to search by any part of the name.
     */
    public function scopeSearchName($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('middle_name', 'like', "%{$search}%");
        });
    }

    /**
     * Get the guest associated with the user.
     */
    public function guest()
    {
        return $this->hasOne(Guest::class);
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get the activity logs for the user.
     */
    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Get the API tokens issued to the user (mobile app auth).
     */
    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * Account was placed into the 30-day temporary-deletion state and
     * hasn't been restored or purged yet. `restore_deadline` distinguishes
     * "still restorable" from "grace period elapsed" - see isRestorable().
     */
    public function isPendingDeletion(): bool
    {
        return $this->deleted_at !== null;
    }

    /** True only while still inside the 30-day restore window. */
    public function isRestorable(): bool
    {
        return $this->isPendingDeletion() && $this->restore_deadline !== null && $this->restore_deadline->isFuture();
    }

    /** Single-line "Street, Barangay, City, Province, Region, Country" for display, skipping any empty parts. */
    public function getComposedAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->street, $this->barangay, $this->city, $this->province, $this->region, $this->country,
        ], fn ($p) => filled($p));

        return $parts ? implode(', ', $parts) : null;
    }
}
