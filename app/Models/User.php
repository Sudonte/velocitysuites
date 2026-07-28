<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
        ];
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
}
