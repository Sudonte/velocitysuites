<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AnnouncementManagementController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * Display list of announcements.
     */
    public function index(Request $request): View
    {
        $query = Announcement::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('content', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        match ($request->get('sort', 'newest')) {
            'oldest' => $query->oldest('id'),
            'title_asc' => $query->orderBy('title'),
            'title_desc' => $query->orderByDesc('title'),
            'published_desc' => $query->orderByDesc('published_at'),
            'published_asc' => $query->orderBy('published_at'),
            default => $query->latest('id'),
        };

        $announcements = $query->paginate(15)->withQueryString();

        return view('admin.announcements.index', compact('announcements'));
    }

    public function create(): View
    {
        return view('admin.announcements.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['images'] = $this->storeImages($request);

        $announcement = Announcement::create($validated);
        $this->notifyIfPublished($announcement);

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement created successfully!');
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.edit', compact('announcement'));
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $validated = $this->validated($request);

        $newImages = $this->storeImages($request);
        if (! empty($newImages)) {
            foreach ($announcement->images ?? [] as $oldPath) {
                Storage::disk('public')->delete($oldPath);
            }
            $validated['images'] = $newImages;
        }

        $announcement->update($validated);
        $this->notifyIfPublished($announcement->fresh());

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement updated successfully!');
    }

    /**
     * Publish a draft/archived announcement - sets published_at to today
     * only if it was never set before, so re-publishing something that
     * was briefly unpublished doesn't bump its display date/ordering.
     */
    public function publish(Announcement $announcement): RedirectResponse
    {
        $announcement->update([
            'status' => 'published',
            'published_at' => $announcement->published_at ?? now()->toDateString(),
        ]);
        $this->notifyIfPublished($announcement->fresh());

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement published.');
    }

    public function unpublish(Announcement $announcement): RedirectResponse
    {
        $announcement->update(['status' => 'archived']);

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement unpublished.');
    }

    /**
     * Deletes the announcement and its stored images. Notifications already
     * sent for it are deliberately left untouched - each one is a
     * self-contained snapshot (title/content/audience captured at send
     * time, see NotificationService::notifyAnnouncement()), not a live
     * reference to this row, so recipients keep their notification history
     * even after the source announcement is gone.
     */
    public function destroy(Announcement $announcement): RedirectResponse
    {
        foreach ($announcement->images ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $announcement->delete();

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement deleted.');
    }

    /**
     * Sends one notification per targeted role (guest/manager/receptionist)
     * the first time an announcement actually goes live (published status +
     * a due publish date) - guarded by notified_at so a later edit to an
     * already-notified announcement doesn't re-blast everyone again.
     */
    private function notifyIfPublished(Announcement $announcement): void
    {
        $isLive = $announcement->status === 'published'
            && $announcement->published_at !== null
            && $announcement->published_at->lte(now());

        if (! $isLive || $announcement->notified_at !== null) {
            return;
        }

        $this->notifications->notifyAnnouncement($announcement);
        $announcement->forceFill(['notified_at' => now()])->save();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published,archived',
            'published_at' => 'nullable|date',
            'target_audience' => 'nullable|array',
            'target_audience.*' => 'in:' . implode(',', Announcement::AUDIENCES),
        ]);
    }

    /**
     * Stores every uploaded image (same validation convention as
     * ProfileController::updateProfilePicture()) and returns the array of
     * stored paths, or [] if none were uploaded this request.
     */
    private function storeImages(Request $request): array
    {
        if (! $request->hasFile('images')) {
            return [];
        }

        $request->validate([
            'images.*' => 'image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $paths = [];
        foreach ($request->file('images') as $file) {
            $paths[] = $file->store('announcement-images', 'public');
        }

        return $paths;
    }
}
