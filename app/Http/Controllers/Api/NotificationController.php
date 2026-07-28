<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Same query as NotificationController@index (the web one).
     */
    public function index(): JsonResponse
    {
        return response()->json(
            auth()->user()->notifications()->latest()->paginate(20)
        );
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsRead();

        return response()->json($notification);
    }

    public function markAllAsRead(): JsonResponse
    {
        auth()->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
