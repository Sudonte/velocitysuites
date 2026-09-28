<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'category',
        'reference_id',
        'target_audience',
        'receipt_number',
        'receipt_type',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'target_audience' => 'array',
    ];

    /**
     * Get the user associated with the notification.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead()
    {
        $this->update(['is_read' => true]);
        self::forgetUnreadCountFor($this->user_id);
    }

    /**
     * Mark notification as unread.
     */
    public function markAsUnread()
    {
        $this->update(['is_read' => false]);
        self::forgetUnreadCountFor($this->user_id);
    }

    /**
     * Unread count for the navbar bell badge - rendered on every
     * authenticated page load for every role, so an uncached COUNT here
     * meant every single page navigation paid for it. Short TTL (not
     * invalidate-on-every-write) is the deliberate choice: a notification
     * created for this user by some other action (a role-wide broadcast,
     * another staff member's checkout, etc.) has dozens of call sites
     * across the app, and missing even one would leave the badge stale
     * forever - a 20s staleness window on a badge count is a fair trade
     * for not having to touch every notify*() call site. The two actions
     * that DO make the count visibly wrong to the acting user themselves
     * (markAsRead/markAllAsRead below) explicitly bust the cache instead
     * of waiting out the TTL.
     */
    public static function unreadCountFor(int $userId): int
    {
        return Cache::remember(
            "notifications:unread_count:{$userId}",
            now()->addSeconds(20),
            fn () => self::where('user_id', $userId)->where('is_read', false)->count()
        );
    }

    public static function forgetUnreadCountFor(int $userId): void
    {
        Cache::forget("notifications:unread_count:{$userId}");
    }
}
