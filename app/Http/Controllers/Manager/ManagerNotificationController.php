<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Http\Controllers\NotificationController;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManagerNotificationController extends Controller
{
    /**
     * Display the manager's notifications, optionally filtered by a text
     * search (title/message), category, and/or a created_at date range.
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'category' => 'nullable|string|in:' . implode(',', NotificationController::CATEGORIES),
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'view' => 'nullable|in:inbox,archived',
        ]);
        $view = $validated['view'] ?? 'inbox';

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
            ->when($view === 'archived', fn ($q) => $q->archived(), fn ($q) => $q->notArchived())
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

        $categories = NotificationController::CATEGORIES;

        return view('manager.notifications.index', compact('notifications', 'categories', 'view'));
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, Notification $notification): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            abort(403);
        }

        $notification->markAsRead();

        // Opening a notification marks it read in the background (fetch).
        if ($request->expectsJson()) {
            return response()->json(['read' => true, 'unread_count' => Notification::unreadCountFor(auth()->id())]);
        }

        return back()->with('success', 'Notification marked as read.');
    }

    /**
     * Mark a notification as unread.
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
        auth()->user()->notifications()->notArchived()->where('is_read', false)->update(['is_read' => true]);
        Notification::forgetUnreadCountFor(auth()->id());

        return back()->with('success', 'All notifications marked as read.');
    }

    /**
     * Archive a notification (notifications are never deleted). It leaves
     * the inbox and can be restored from the Archived view.
     */
    public function archive(Notification $notification): RedirectResponse
    {
        if ($notification->user_id !== auth()->id()) {
            abort(403);
        }

        $notification->archive();

        return back()->with('success', 'Notification archived.');
    }

    /** Bring an archived notification back to the inbox. */
    public function restore(Notification $notification): RedirectResponse
    {
        if ($notification->user_id !== auth()->id()) {
            abort(403);
        }

        $notification->restore();

        return back()->with('success', 'Notification restored to your inbox.');
    }

    /** Archive every read notification in the inbox (replaces "clear"). */
    public function archiveAllRead(): RedirectResponse
    {
        $count = auth()->user()->notifications()->notArchived()->where('is_read', true)->update(['archived_at' => now()]);

        return back()->with('success', $count ? "{$count} read notification(s) archived." : 'No read notifications to archive.');
    }
}
