<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin CRUD for authorized discounts (Senior Citizen, PWD, Student,
 * etc.) - genuinely separate from Promotions, mirrors
 * PromotionManagementController's pattern without the amenity pivot.
 */
class DiscountManagementController extends Controller
{
    /**
     * The Discounts tab of Promos & Discounts. Filters: name/description, percentage or fixed, when it is
     * offered, and status - where "Archived" is a status choice (archived discounts are hidden otherwise).
     */
    public function index(Request $request): View
    {
        $query = Discount::query();
        $filters = [
            'search' => trim((string) $request->get('search', '')),
            // an old ?view=archived link still lands on the archived list
            'status' => (string) $request->get('status', $request->get('view') === 'archived' ? 'archived' : ''),
            'type' => (string) $request->get('type', ''),
            'period' => (string) $request->get('period', ''),
        ];

        $filters['status'] === 'archived' ? $query->archived() : $query->notArchived();
        if (in_array($filters['status'], ['active', 'inactive'], true)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
        }

        if (in_array($filters['type'], ['percentage', 'fixed'], true)) {
            $query->where('discount_type', $filters['type']);
        }

        $today = \App\Support\CheckInWindow::today()->toDateString();
        match ($filters['period']) {
            'current' => $query->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today)),
            'upcoming' => $query->where('start_date', '>', $today),
            'expired' => $query->where('end_date', '<', $today),
            default => null,
        };

        $discounts = $query->latest()->paginate(\App\Support\PerPage::resolve($request))->withQueryString();

        return view('admin.discounts.index', compact('discounts', 'filters'));
    }

    public function create(): View
    {
        return view('admin.discounts.create');
    }

    private function validateDiscount(Request $request, ?Discount $discount = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            // Optional validity window (inclusive, hotel-local days); empty = no limit on that side.
            'start_date' => ['nullable', 'date', $this->notBeforeToday($discount?->start_date?->toDateString())],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', $this->notBeforeToday($discount?->end_date?->toDateString())],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $discount = Discount::create($this->validateDiscount($request));

        Activity::log('Created discount', $discount->name, $discount);

        return redirect()->route('admin.discounts.index')->with('success', 'Discount created successfully!');
    }

    public function edit(Discount $discount): View
    {
        return view('admin.discounts.edit', compact('discount'));
    }

    public function update(Request $request, Discount $discount): RedirectResponse
    {
        $discount->update($this->validateDiscount($request, $discount));

        Activity::log('Updated discount', $discount->name, $discount);

        return redirect()->route('admin.discounts.index')->with('success', 'Discount updated successfully!');
    }

    public function toggle(Discount $discount): RedirectResponse
    {
        $newStatus = $discount->status === 'active' ? 'inactive' : 'active';
        $discount->update(['status' => $newStatus]);

        Activity::log("Set discount to {$newStatus}", $discount->name, $discount);

        return redirect()->route('admin.discounts.index')->with('success', "Discount {$newStatus}d successfully!");
    }

    /** Archive instead of delete - it leaves the list and is switched off; restore it from the Archived status filter. */
    public function archive(Discount $discount): \Illuminate\Http\RedirectResponse
    {
        $discount->archiveRecord();
        \App\Support\Activity::log('Archived discount', $this->archiveLabel($discount), $discount);

        return redirect()->route('admin.discounts.index')->with('success', ucfirst('discount') . ' archived. Choose Status: Archived in the search to find and restore it.');
    }

    public function unarchive(Discount $discount): \Illuminate\Http\RedirectResponse
    {
        $discount->unarchive();
        \App\Support\Activity::log('Restored discount', $this->archiveLabel($discount), $discount);

        return redirect()->route('admin.discounts.index', ['status' => 'archived'])->with('success', ucfirst('discount') . ' restored - it is inactive until you switch it on.');
    }

    private function archiveLabel(Discount $discount): string
    {
        return (string) ($discount->name ?? $discount->title ?? $discount->amenity_name ?? "#{$discount->id}");
    }

    /** A date that can't be before today - unless it's the value already stored (editing a running discount). */
    private function notBeforeToday(?string $current): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($current) {
            if ($value && $value !== $current && \Carbon\Carbon::parse($value)->lt(\App\Support\CheckInWindow::date())) {
                $fail('The ' . str_replace('_', ' ', $attribute) . ' cannot be before today.');
            }
        };
    }
}
