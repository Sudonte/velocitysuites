<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    /**
     * Display list of users.
     */
    public function index(Request $request): View
    {
        $query = User::query();

        // Search
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', '%' . $request->search . '%')
                  ->orWhere('last_name', 'like', '%' . $request->search . '%')
                  ->orWhere('middle_name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        // Filter by role
        if ($request->has('role') && $request->role) {
            $query->where('role', $request->role);
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $users = $query->paginate(15);

        return view('admin.users.index', compact('users'));
    }

    /**
     * Show create user form.
     */
    public function create(): View
    {
        return view('admin.users.create');
    }

    /**
     * Store a new user.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:admin,manager,receptionist,guest',
            'status' => 'required|in:active,suspended',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        User::create($validated);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully!');
    }

    /**
     * Show edit user form.
     */
    public function edit(User $user): View
    {
        return view('admin.users.edit', compact('user'));
    }

    /**
     * Update user information.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:admin,manager,receptionist,guest',
            'status' => 'required|in:active,suspended',
        ]);

        $user->update($validated);

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully!');
    }

    /**
     * Reset user password.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        $tempPassword = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $user->update(['password' => Hash::make($tempPassword)]);

        // TODO: Send email with temporary password

        return redirect()->route('admin.users.index')->with('success', 'Password reset! Temporary password: ' . $tempPassword);
    }

    /**
     * Deactivate user.
     */
    public function deactivate(User $user): RedirectResponse
    {
        $user->update(['status' => 'suspended']);

        return redirect()->route('admin.users.index')->with('success', 'User deactivated successfully!');
    }

    /**
     * Reactivate user.
     */
    public function reactivate(User $user): RedirectResponse
    {
        $user->update(['status' => 'active']);

        return redirect()->route('admin.users.index')->with('success', 'User reactivated successfully!');
    }

    /**
     * Delete user.
     */
    public function destroy(User $user): RedirectResponse
    {
        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully!');
    }
}
