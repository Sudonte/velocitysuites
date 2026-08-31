<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Curated, human-readable activity logging for dashboard feeds - a thin
 * wrapper over ActivityLog that captures a meaningful action/description
 * plus an optional subject (for the "View" link on the dashboard feeds),
 * instead of the generic "{METHOD} {path}" the LogActivity middleware
 * falls back to for anything not explicitly logged this way.
 *
 * Marks the current request so LogActivity's fallback logging skips it -
 * a mutating request that already called Activity::log() shouldn't also
 * get a second, noisier row from the middleware.
 */
class Activity
{
    public static function log(string $action, ?string $description = null, ?Model $subject = null): void
    {
        if (! auth()->check()) {
            return;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject ? static::subjectTypeFor($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => request()->ip(),
        ]);

        request()->attributes->set('activity_logged', true);
    }

    /**
     * Short, stable subject-type strings (not full class names) so
     * ActivityLog::subjectUrl() can match on them without depending on
     * the model's namespace.
     */
    private static function subjectTypeFor(Model $subject): string
    {
        return match (get_class($subject)) {
            \App\Models\Reservation::class => 'reservation',
            \App\Models\Booking::class => 'booking',
            \App\Models\User::class => 'user',
            \App\Models\Promotion::class => 'promotion',
            \App\Models\Discount::class => 'discount',
            \App\Models\StaffPasswordResetRequest::class => 'staff_password_reset_request',
            default => class_basename($subject),
        };
    }
}
