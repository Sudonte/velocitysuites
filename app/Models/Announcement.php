<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'images',
        'status',
        'published_at',
        'target_audience',
        'notified_at',
    ];

    protected $casts = [
        'images' => 'array',
        'target_audience' => 'array',
        'published_at' => 'date',
        'notified_at' => 'datetime',
    ];

    public const AUDIENCES = ['public', 'guest', 'manager', 'receptionist'];

    /** Roles that actually have a notifications inbox - 'public' visitors don't have an account to notify. */
    public const NOTIFIABLE_ROLES = ['guest', 'manager', 'receptionist'];

    /**
     * Friendly display labels - 'guest' explicitly calls out the mobile app
     * since a guest account reaches both surfaces with the exact same
     * notification, not a separate "mobile" audience value.
     */
    public const AUDIENCE_LABELS = [
        'public' => 'Public',
        'guest' => 'Guest & Mobile App',
        'manager' => 'Manager',
        'receptionist' => 'Receptionist',
    ];

    public static function audienceLabel(string $role): string
    {
        return self::AUDIENCE_LABELS[$role] ?? ucfirst($role);
    }

    /**
     * Human-readable summary of a target_audience array - "All Audiences"
     * for null/empty (matches scopeVisibleTo's "null/empty = all" rule
     * below), otherwise a comma-joined list of friendly labels. Shared by
     * the admin table, the public Home page cards, and notification detail
     * views so the phrasing never drifts between them.
     */
    public static function audienceSummary(?array $roles): string
    {
        if (empty($roles)) {
            return 'All Audiences';
        }

        return collect($roles)->map(fn ($role) => self::audienceLabel($role))->implode(', ');
    }

    /**
     * Which roles should be notified when this announcement goes live - a
     * null/empty target_audience means "all audiences" (same rule as
     * scopeVisibleTo above), so it resolves to every notifiable role.
     */
    public function notifiableRoles(): array
    {
        if (empty($this->target_audience)) {
            return self::NOTIFIABLE_ROLES;
        }

        return array_values(array_intersect($this->target_audience, self::NOTIFIABLE_ROLES));
    }

    /**
     * Published, already-due announcements visible to a given audience -
     * a null/empty target_audience means "all audiences", not "none", so
     * it always matches regardless of $audience. Used identically by the
     * public Home page, every role dashboard widget, and the mobile app's
     * Api\AnnouncementController - one query, several callers, so the
     * "who sees what" rule can never drift between them.
     */
    public function scopeVisibleTo(Builder $query, string $audience): Builder
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhereDate('published_at', '<=', now());
            })
            ->where(function ($q) use ($audience) {
                $q->whereNull('target_audience')
                    ->orWhereJsonLength('target_audience', 0)
                    ->orWhereJsonContains('target_audience', $audience);
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * First stored image as an absolute public-disk URL, or null - the
     * convenience accessor every card/widget uses instead of repeating
     * the array-first-element + Storage::url() dance at each call site.
     */
    public function getFirstImageUrlAttribute(): ?string
    {
        $path = $this->images[0] ?? null;

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
