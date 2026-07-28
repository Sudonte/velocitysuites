<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
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
    public function index(Request $request): View
    {
        $query = Discount::query();

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $discounts = $query->latest()->paginate(15);

        return view('admin.discounts.index', compact('discounts'));
    }

    public function create(): View
    {
        return view('admin.discounts.create');
    }

    private function validateDiscount(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Discount::create($this->validateDiscount($request));

        return redirect()->route('admin.discounts.index')->with('success', 'Discount created successfully!');
    }

    public function edit(Discount $discount): View
    {
        return view('admin.discounts.edit', compact('discount'));
    }

    public function update(Request $request, Discount $discount): RedirectResponse
    {
        $discount->update($this->validateDiscount($request));

        return redirect()->route('admin.discounts.index')->with('success', 'Discount updated successfully!');
    }

    public function toggle(Discount $discount): RedirectResponse
    {
        $newStatus = $discount->status === 'active' ? 'inactive' : 'active';
        $discount->update(['status' => $newStatus]);

        return redirect()->route('admin.discounts.index')->with('success', "Discount {$newStatus}d successfully!");
    }

    public function destroy(Discount $discount): RedirectResponse
    {
        $discount->delete();

        return redirect()->route('admin.discounts.index')->with('success', 'Discount deleted successfully!');
    }
}
