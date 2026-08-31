<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Controller;
use App\Models\StaffPasswordResetRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Gate that sits between "logged in with the shared default password" and
 * the dashboard - LoginController::login() redirects here instead of the
 * normal dashboard whenever Hash::check() shows the account is still on
 * UserManagementController::DEFAULT_STAFF_PASSWORD (set at account
 * creation, or by the admin's resetPassword() action).
 */
class ForcePasswordChangeController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (! Hash::check(UserManagementController::DEFAULT_STAFF_PASSWORD, auth()->user()->password)) {
            return redirect()->to($this->dashboardFor(auth()->user()->role));
        }

        return view('auth.force-change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = auth()->user();

        if (strtolower($validated['password']) === strtolower(UserManagementController::DEFAULT_STAFF_PASSWORD)) {
            return back()->withErrors(['password' => 'Choose a password other than the default one.']);
        }

        $user->update(['password' => $validated['password']]);

        // Closes the loop on a Manager/Receptionist password-reset request:
        // if this account got here via an approved request (see
        // Admin\PasswordResetRequestController::approve()), mark it
        // completed now that a real permanent password is actually set.
        // No-op for accounts that reached this screen via plain account
        // creation or the admin's direct reset button with no open request.
        StaffPasswordResetRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->latest()
            ->first()
            ?->update(['status' => 'completed', 'completed_at' => now()]);

        return redirect()->to($this->dashboardFor($user->role))
            ->with('success', 'Password updated. Welcome!');
    }

    private function dashboardFor(string $role): string
    {
        return match ($role) {
            'admin' => route('admin.dashboard'),
            'manager' => route('manager.dashboard'),
            'receptionist' => route('receptionist.dashboard'),
            'guest' => route('guest.dashboard'),
            default => route('home'),
        };
    }
}