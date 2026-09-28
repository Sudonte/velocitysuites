<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /**
     * Known notification categories - kept in sync with
     * App\Services\NotificationService's call sites (general/booking/
     * payment/check_in/check_out/checkin_reminder/announcement). Used both
     * to populate the category filter dropdown and to validate the
     * incoming filter input.
     */
    public const CATEGORIES = [
        'general',
        'booking',
        'payment',
        'check_in',
        'check_out',
        'checkin_reminder',
        'announcement',
    ];

    /**
     * Show all notifications for the authenticated user, optionally
     * filtered by a text search (title/message), category, and/or a
     * created_at date range.
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'category' => 'nullable|string|in:' . implode(',', self::CATEGORIES),
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        // simplePaginate (Previous/Next only, no numbered page-link boxes) -
        // the numbered links render broken/oversized here for reasons that
        // don't trace back to anything in this app's own CSS, same fix
        // already applied everywhere else in the app.
        $notifications = auth()->user()->notifications()
            ->when($validated['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('message', 'like', "%{$search}%");
                });
            })
            ->when($validated['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($dateFrom, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($dateTo, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->simplePaginate(20)
            ->withQueryString();

        $categories = self::CATEGORIES;

        return view('notifications.index', compact('notifications', 'categories'));
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Notification $notification): RedirectResponse
    {
        if ($notification->user_id !== auth()->id()) {
            abort(403);
        }
        $notification->markAsRead();
        return back()->with('success', 'Notification marked as read.');
    }

    /**
     * Mark a single notification as unread.
     */
    public function markAsUnread(Notification $notification): RedirectResponse
    {
        if ($notification->user_id !== auth()->id()) {
            abort(403);
        }
        $notification->markAsUnread();
        return back()->with('success', 'Notification marked as unread.');
    }

    /**
     * Mark all notifications as read - deliberately unscoped by whatever
     * search/category/date filter is currently active on the index page;
     * "Mark All as Read" always means every one of the user's unread
     * notifications, not just the filtered/visible subset.
     */
    public function markAllAsRead(): RedirectResponse
    {
        auth()->user()->notifications()->where('is_read', false)->update(['is_read' => true]);
        Notification::forgetUnreadCountFor(auth()->id());
        return back()->with('success', 'All notifications marked as read.');
    }

    /**
     * Permanently delete a single notification. If it was unread, the
     * cached unread count must be busted here explicitly - markAsRead()/
     * markAsUnread() already do this via their own model methods, but a
     * delete has no such hook, so this is the one call site that must
     * remember to do it itself.
     */
    public function destroy(Notification $notification): RedirectResponse
    {
        if ($notification->user_id !== auth()->id()) {
            abort(403);
        }

        if (! $notification->is_read) {
            Notification::forgetUnreadCountFor($notification->user_id);
        }

        $notification->delete();

        return back()->with('success', 'Notification deleted.');
    }
}
