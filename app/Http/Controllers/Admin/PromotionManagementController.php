<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\RoomType;
use App\Services\NotificationService;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PromotionManagementController extends Controller
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * The Promos tab of Promos & Discounts. Filters: name/description, room type, what the promo includes,
     * when it runs, and status - where "Archived" is a status choice (archived promos are hidden otherwise).
     */
    public function index(Request $request): View
    {
        $query = Promotion::query();
        $filters = [
            'search' => trim((string) $request->get('search', '')),
            // an old ?view=archived link still lands on the archived list
            'status' => (string) $request->get('status', $request->get('view') === 'archived' ? 'archived' : ''),
            'room_type' => (string) $request->get('room_type', ''),
            'includes' => (string) $request->get('includes', ''),
            'period' => (string) $request->get('period', ''),
        ];

        $filters['status'] === 'archived' ? $query->archived() : $query->notArchived();
        if (in_array($filters['status'], ['active', 'inactive'], true)) {
            $query->where('status', $filters['status']);
        }

        // Grouped so the other filters AND against the whole name-or-description match.
        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('promo_name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
        }

        // A room type shows the promos that apply to it: its own and the ones for every type.
        if ($filters['room_type'] === 'all') {
            $query->whereNull('room_type_id');
        } elseif (ctype_digit($filters['room_type'])) {
            $query->where(fn ($q) => $q->whereNull('room_type_id')->orWhere('room_type_id', (int) $filters['room_type']));
        }

        $withDiscount = fn ($q) => $q->whereIn('discount_type', ['percentage', 'fixed'])->where('discount_value', '>', 0);
        match ($filters['includes']) {
            'amenities' => $query->has('amenities')->whereNot($withDiscount),
            'discount' => $query->doesntHave('amenities')->where($withDiscount),
            'both' => $query->has('amenities')->where($withDiscount),
            default => null,
        };

        $today = \App\Support\CheckInWindow::date();
        match ($filters['period']) {
            'running' => $query->runningOn($today),
            'upcoming' => $query->whereDate('start_date', '>', $today),
            'ended' => $query->whereDate('end_date', '<', $today),
            default => null,
        };

        $promotions = $query->with(['amenities', 'roomType'])->latest()->paginate(\App\Support\PerPage::resolve($request))->withQueryString();
        $roomTypes = RoomType::orderBy('name')->get();

        return view('admin.promotions.index', compact('promotions', 'roomTypes', 'filters'));
    }

    /**
     * Show create promotion form.
     */
    public function create(): View
    {
        $roomTypes = RoomType::orderBy('name')->get();
        $amenities = \App\Models\Amenity::where('status', 'active')->orderBy('amenity_name')->get();

        return view('admin.promotions.create', compact('roomTypes', 'amenities'));
    }

    /**
     * Validate a promotion request. A promo includes free amenities
     * (amenities[<id>] = quantity, 0/blank meaning excluded), a discount
     * (has_discount + discount_type/discount_value/discount_applies_to), or
     * both - at least one. The discount comes off either the rate of rooms of
     * its room type, or the stay's total from before check-in
     * (Promotion::discountFor). ID-checked discounts (Senior Citizen, PWD,
     * etc.) are the separate Discount module.
     */
    private function validatePromotion(Request $request, ?Promotion $promotion = null): array
    {
        $validated = $request->validate([
            'promo_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'room_type_id' => 'nullable|exists:room_types,id',
            // One-time: real dates, not in the past (an unchanged start date on an
            // already-running promotion is allowed). Yearly: month + day only.
            'is_recurring' => 'nullable|boolean',
            'start_date' => ['exclude_if:is_recurring,1', 'required', 'date', $this->notBeforeToday($promotion?->start_date?->toDateString())],
            'end_date' => ['exclude_if:is_recurring,1', 'required', 'date', 'after_or_equal:start_date', 'after_or_equal:today'],
            'recur_start_month' => 'exclude_unless:is_recurring,1|required|integer|between:1,12',
            'recur_start_day' => 'exclude_unless:is_recurring,1|required|integer|between:1,31',
            'recur_end_month' => 'exclude_unless:is_recurring,1|required|integer|between:1,12',
            'recur_end_day' => 'exclude_unless:is_recurring,1|required|integer|between:1,31',
            'status' => 'required|in:active,inactive',
            'amenities' => 'array',
            'amenities.*' => 'nullable|integer|min:0|max:99',
            'has_discount' => 'nullable|boolean',
            'discount_type' => 'exclude_unless:has_discount,1|required|in:percentage,fixed',
            // off the room rate (of its room type) or off the total from before check-in - one or the other
            'discount_applies_to' => 'exclude_unless:has_discount,1|required|in:rate,total',
            'discount_value' => ['exclude_unless:has_discount,1', 'required', 'numeric', 'min:0.01', 'max:99999999',
                fn ($attribute, $value, $fail) => $request->input('discount_type') === 'percentage' && (float) $value > 100
                    ? $fail('A percentage discount cannot be more than 100%.') : null],
        ]);

        $amenityIds = array_keys($validated['amenities'] ?? []);
        $knownIds = \App\Models\Amenity::whereIn('id', $amenityIds)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (array_diff(array_map('strval', $amenityIds), $knownIds)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amenities' => 'One of the selected amenities no longer exists. Remove it and try again.',
            ]);
        }

        $included = collect($validated['amenities'] ?? [])->filter(fn ($qty) => (int) $qty > 0);
        $hasDiscount = (bool) ($validated['has_discount'] ?? false);
        if ($included->isEmpty() && ! $hasDiscount) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amenities' => 'Add at least one amenity or a discount to this promotion.',
            ]);
        }
        if (! $hasDiscount) {
            $validated['discount_type'] = null;
            $validated['discount_value'] = null;
            $validated['discount_applies_to'] = Promotion::APPLIES_TO_RATE;
        }
        unset($validated['has_discount']);

        // informational only - what counts is the amenities and the discount it actually has
        $validated['promo_type'] = $included->isNotEmpty() ? 'amenity' : 'discount';

        $validated['is_recurring'] = (bool) ($validated['is_recurring'] ?? false);
        if ($validated['is_recurring']) {
            foreach (['start', 'end'] as $key) {
                $month = (int) $validated["recur_{$key}_month"];
                $day = (int) $validated["recur_{$key}_day"];
                if (! checkdate($month, $day, 2000)) { // 2000 is a leap year, so Feb 29 is allowed
                    throw \Illuminate\Validation\ValidationException::withMessages(["recur_{$key}_day" => 'That day does not exist in the chosen month.']);
                }
                $validated["recur_{$key}_md"] = $month * 100 + $day;
                unset($validated["recur_{$key}_month"], $validated["recur_{$key}_day"]);
            }
            $probe = new Promotion(['is_recurring' => true, 'recur_start_md' => $validated['recur_start_md'], 'recur_end_md' => $validated['recur_end_md']]);
            $probe->syncRecurringDates();
            $validated['start_date'] = $probe->start_date;
            $validated['end_date'] = $probe->end_date;
        } else {
            $validated['recur_start_md'] = null;
            $validated['recur_end_md'] = null;
        }

        return $validated;
    }

    /**
     * Sync the included-amenities pivot from the validated payload.
     */
    private function syncAmenities(Promotion $promotion, array $validated): void
    {
        $sync = collect($validated['amenities'] ?? [])
            ->filter(fn ($qty) => (int) $qty > 0)
            ->map(fn ($qty) => ['quantity' => (int) $qty])
            ->all();
        $promotion->amenities()->sync($sync);
    }

    /**
     * Stores the uploaded promo image (same validation convention as
     * Admin\AnnouncementManagementController::storeImages()) and returns
     * the stored path, or null if none was uploaded this request.
     */
    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $request->validate([
            'image' => 'image|mimes:jpeg,png,jpg|max:5120',
        ]);

        return $request->file('image')->store('promotion-images', 'public');
    }

    /**
     * Store a new promotion.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePromotion($request);
        $validated['image'] = $this->storeImage($request);

        $promotion = Promotion::create($validated);
        $this->syncAmenities($promotion, $validated);

        Activity::log('Created promotion', $promotion->promo_name, $promotion);
        $this->notifyIfActive($promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion created successfully!');
    }

    /**
     * Show edit promotion form.
     */
    public function edit(Promotion $promotion): View
    {
        $roomTypes = RoomType::orderBy('name')->get();
        $promotion->load('amenities');
        // Active amenities, plus any already on this promotion even if since deactivated.
        $amenities = \App\Models\Amenity::where('status', 'active')
            ->orWhereIn('id', $promotion->amenities->pluck('id'))
            ->orderBy('amenity_name')->get();

        return view('admin.promotions.edit', compact('promotion', 'roomTypes', 'amenities'));
    }

    /**
     * Update promotion information.
     */
    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $validated = $this->validatePromotion($request, $promotion);

        $newImage = $this->storeImage($request);
        if ($newImage !== null) {
            if ($promotion->image) {
                Storage::disk('public')->delete($promotion->image);
            }
            $validated['image'] = $newImage;
        }

        $promotion->update($validated);
        $this->syncAmenities($promotion, $validated);

        Activity::log('Updated promotion', $promotion->promo_name, $promotion);
        $this->notifyIfActive($promotion->fresh());

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion updated successfully!');
    }

    /**
     * Toggle promotion status between active and inactive.
     */
    public function toggle(Promotion $promotion): RedirectResponse
    {
        $newStatus = $promotion->status === 'active' ? 'inactive' : 'active';
        $promotion->update(['status' => $newStatus]);

        Activity::log("Set promotion to {$newStatus}", $promotion->promo_name, $promotion);
        $this->notifyIfActive($promotion->fresh());

        return redirect()->route('admin.promotions.index')
            ->with('success', "Promotion {$newStatus}d successfully!");
    }

    /**
     * Notifies every active guest the first time a promotion actually goes
     * live (status=active + a due start_date) - guarded by notified_at so a
     * later edit to an already-notified, still-active promotion doesn't
     * re-blast everyone again. Mirrors Admin\AnnouncementManagementController::
     * notifyIfPublished()'s exact guard shape.
     */
    private function notifyIfActive(Promotion $promotion): void
    {
        $isLive = $promotion->status === 'active'
            && $promotion->start_date !== null
            && $promotion->start_date->lte(now());

        if (! $isLive || $promotion->notified_at !== null) {
            return;
        }

        $this->notifications->notifyPromotion($promotion);
        $promotion->forceFill(['notified_at' => now()])->save();
    }

    /** Archive instead of delete - it leaves the list and is switched off; restore it from the Archived status filter. */
    public function archive(Promotion $promotion): \Illuminate\Http\RedirectResponse
    {
        $promotion->archiveRecord();
        \App\Support\Activity::log('Archived promotion', $this->archiveLabel($promotion), $promotion);

        return redirect()->route('admin.promotions.index')->with('success', ucfirst('promotion') . ' archived. Choose Status: Archived in the search to find and restore it.');
    }

    public function unarchive(Promotion $promotion): \Illuminate\Http\RedirectResponse
    {
        $promotion->unarchive();
        \App\Support\Activity::log('Restored promotion', $this->archiveLabel($promotion), $promotion);

        return redirect()->route('admin.promotions.index', ['status' => 'archived'])->with('success', ucfirst('promotion') . ' restored - it is inactive until you switch it on.');
    }

    private function archiveLabel(Promotion $promotion): string
    {
        return (string) ($promotion->name ?? $promotion->title ?? $promotion->amenity_name ?? "#{$promotion->id}");
    }

    /** A date that can't be before today - unless it's the value already stored (editing a running promotion/discount). */
    private function notBeforeToday(?string $current): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($current) {
            if ($value && $value !== $current && \Carbon\Carbon::parse($value)->lt(\App\Support\CheckInWindow::date())) {
                $fail('The ' . str_replace('_', ' ', $attribute) . ' can\'t be before today.');
            }
        };
    }
}
