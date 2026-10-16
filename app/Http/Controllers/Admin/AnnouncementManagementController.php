<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
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
        $view = $request->get('view') === 'archived' ? 'archived' : 'active';
        $view === 'archived' ? $query->archived() : $query->notArchived();

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

        $announcements = $query->paginate(\App\Support\PerPage::resolve($request))->withQueryString();

        return view('admin.announcements.index', compact('announcements', 'view'));
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

        Activity::log('Created announcement', $request->input('title'));

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

        Activity::log('Updated announcement', $announcement->title, $announcement);

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
            'published_at' => $announcement->published_at ?? \App\Support\CheckInWindow::date()->toDateString(),
        ]);
        $this->notifyIfPublished($announcement->fresh());

        Activity::log('Published announcement', $announcement->title, $announcement);

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement published.');
    }

    public function unpublish(Announcement $announcement): RedirectResponse
    {
        $announcement->update(['status' => 'archived']);

        Activity::log('Unpublished announcement', $announcement->title, $announcement);

        return redirect()->route('admin.announcements.index')->with('success', 'Announcement unpublished.');
    }

    /** Archive instead of delete - it leaves the list and is switched off; restore from the Archived tab. */
    public function archive(Announcement $announcement): \Illuminate\Http\RedirectResponse
    {
        $announcement->archiveRecord();
        \App\Support\Activity::log('Archived announcement', $this->archiveLabel($announcement), $announcement);

        return redirect()->route('admin.announcements.index')->with('success', ucfirst('announcement') . ' archived. You can restore it from the Archived tab.');
    }

    public function unarchive(Announcement $announcement): \Illuminate\Http\RedirectResponse
    {
        $announcement->unarchive();
        \App\Support\Activity::log('Restored announcement', $this->archiveLabel($announcement), $announcement);

        return redirect()->route('admin.announcements.index', ['view' => 'archived'])->with('success', ucfirst('announcement') . ' restored - it is draft until you switch it on.');
    }

    private function archiveLabel(Announcement $announcement): string
    {
        return (string) ($announcement->name ?? $announcement->title ?? $announcement->amenity_name ?? "#{$announcement->id}");
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
