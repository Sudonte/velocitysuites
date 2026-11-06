<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use Illuminate\Http\JsonResponse;

/**
 * The website's "System" notifications: the live announcements for guests (Announcement::visibleTo('guest') - the same rule the app's
 * landing list and the first-publish notification use), each as one notification per guest. New = unread for everybody; an edited one moves
 * to the top, unread again, labelled "Updated"; an archived, unpublished or expired one is simply no longer in the list. Read state is
 * kept per guest in announcement_reads. Additive: the app's own /api/notifications is not touched.
 */
class GuestNoticeController extends Controller
{
    public function index(): JsonResponse
    {
        $reads = AnnouncementRead::where('user_id', auth()->id())->pluck('read_at', 'announcement_id');

        $items = Announcement::visibleTo('guest')->notArchived()->get()->map(function (Announcement $a) use ($reads) {
            $at = $a->noticeAt();
            $readAt = $reads[$a->id] ?? null;

            return [
                'id' => 'a' . $a->id,
                'announcement_id' => $a->id,
                'title' => $a->title,
                'message' => $a->content,
                'category' => 'announcement',
                'is_read' => $readAt !== null && ($a->last_edited_at === null || $readAt->gte($a->last_edited_at)),
                'created_at' => $at->toIso8601String(),
                'updated' => $a->last_edited_at !== null,
                'published_at' => optional($a->published_at)->toDateString(),
                'ends_at' => optional($a->ends_at)->toDateString(),
                'images' => collect($a->images ?? [])->map(fn ($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p))->values(),
            ];
        })->sortByDesc('created_at')->values();

        return response()->json(['data' => $items, 'unread_count' => $items->where('is_read', false)->count()]);
    }

    public function read(Announcement $announcement): JsonResponse
    {
        abort_unless(Announcement::visibleTo('guest')->notArchived()->whereKey($announcement->id)->exists(), 404);
        AnnouncementRead::updateOrCreate(['user_id' => auth()->id(), 'announcement_id' => $announcement->id], ['read_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function unread(Announcement $announcement): JsonResponse
    {
        AnnouncementRead::where('user_id', auth()->id())->where('announcement_id', $announcement->id)->delete();

        return response()->json(['message' => 'Marked as unread.']);
    }

    public function readAll(): JsonResponse
    {
        $now = now();
        foreach (Announcement::visibleTo('guest')->notArchived()->pluck('id') as $id) {
            AnnouncementRead::updateOrCreate(['user_id' => auth()->id(), 'announcement_id' => $id], ['read_at' => $now]);
        }

        return response()->json(['message' => 'All system notifications marked as read.']);
    }
}
