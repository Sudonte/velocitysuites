<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ManagerNotificationController extends Controller
{
    /**
     * Display the manager's notifications.
     */
    public function index(): View
    {
        // simplePaginate (Previous/Next only, no numbered page-link boxes) -
        // the numbered links render broken/oversized here for reasons that
        // don't trace back to anything in this app's own CSS, same fix
        // already applied everywhere else in the app.
        $notifications = auth()->user()->notifications()->latest()->simplePaginate(20);

        return view('manager.notifications.index', compact('notifications'));
    }

    /**
     * Mark a notification as read.
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
     * Mark all notifications as read.
     */
    public function markAllAsRead(): RedirectResponse
    {
        auth()->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
