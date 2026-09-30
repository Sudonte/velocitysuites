<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Same query as NotificationController@index (the web one). per_page
     * defaults to 20 for any other caller, but the Android app explicitly
     * requests a high per_page so the dashboard/notification list always
     * see this guest's complete recent history instead of only the latest 20.
     *
     * unread_count is the guest's TRUE total unread count (Notification::
     * unreadCountFor(), the same cached count the web navbar badge already
     * uses) - deliberately NOT derived from this response's own paginated
     * `data`, which only ever holds up to $perPage rows. A guest with more
     * unread notifications than fit in one page must still see their real
     * total, not an undercount silently capped at whatever page size the
     * client happened to request.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 200);
        $paginated = auth()->user()->notifications()->latest()->paginate($perPage);

        return response()->json(array_merge(
            $paginated->toArray(),
            ['unread_count' => Notification::unreadCountFor(auth()->id())]
        ));
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsRead();

        return response()->json($notification);
    }

    /**
     * Counterpart to markAsRead() above - the model method already existed
     * (used by the staff web portal's own NotificationController@markAsUnread/
     * ManagerNotificationController@markAsUnread) but was never exposed to the
     * guest API, so the mobile app had no way to toggle a notification back
     * to unread.
     */
    public function markAsUnread(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsUnread();

        return response()->json($notification);
    }

    public function markAllAsRead(): JsonResponse
    {
        auth()->user()->notifications()->where('is_read', false)->update(['is_read' => true]);
        Notification::forgetUnreadCountFor(auth()->id());

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
