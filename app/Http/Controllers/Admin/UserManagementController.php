<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffPasswordResetRequest;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    /** Every newly-created or admin-reset manager/receptionist account starts on this password. */
    public const DEFAULT_STAFF_PASSWORD = 'velocitysuites123';

    /**
     * Display list of users. Scoped to everyone except other
     * administrator accounts: the logged-in admin's own account, guest
     * accounts, and manager/receptionist accounts are all visible here.
     */
    public function index(Request $request): View
    {
        $query = User::query()->where(function ($q) {
            $q->where('role', '!=', 'admin')->orWhere('id', auth()->id());
        });

        // Search (by name or email)
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

        $pendingResetRequestsCount = StaffPasswordResetRequest::where('status', 'pending')->count();

        return view('admin.users.index', compact('users', 'pendingResetRequestsCount'));
    }

    /**
     * View-only details page. Reachable for anyone in the index scope:
     * the admin's own account, guests, managers, and receptionists.
     */
    public function show(User $user): View
    {
        abort_unless($this->isManageable($user), 404);

        $user->loadMissing('guest.reservations.roomType');
        if ($user->role === 'guest') {
            $user->loadMissing('activityLogs');
        }

        return view('admin.users.show', compact('user'));
    }

    /**
     * Only manager/receptionist accounts can be created here - guests
     * register themselves (web/app) and there is exactly one admin.
     */
    public function create(): View
    {
        return view('admin.users.create');
    }

    /**
     * Email is auto-generated (lastname.role@hotel.com, de-duplicated if
     * needed) and password always starts at DEFAULT_STAFF_PASSWORD - the
     * admin doesn't choose either, matching the fixed convention every
     * staff account is provisioned under. The new hire is expected to
     * set their own permanent password the first time they log in (see
     * LoginController's forced-change-password redirect).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'role' => 'required|in:manager,receptionist',
        ]);

        $email = $this->generateStaffEmail($validated['last_name'], $validated['role']);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $email,
            'password' => self::DEFAULT_STAFF_PASSWORD,
            'role' => $validated['role'],
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Activity::log('Created staff account', "{$user->full_name} ({$validated['role']}, {$email})", $user);

        return redirect()->route('admin.users.index')
            ->with('success', "Account created! Login email: {$email} - default password: " . self::DEFAULT_STAFF_PASSWORD);
    }

    /**
     * Name and status are editable for manager/receptionist accounts.
     * Email and role are fixed at creation time (the email convention is
     * derived from last name + role, so changing either without also
     * regenerating the login email would be confusing/inconsistent) -
     * use resetPassword() for password, deactivate/reactivate for status
     * toggling from the list view.
     */
    public function edit(User $user): View
    {
        abort_unless($this->isElevatedStaff($user), 404);

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->isElevatedStaff($user), 404);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'status' => 'required|in:active,suspended',
        ]);

        $user->update($validated);

        Activity::log('Updated staff account', $user->full_name, $user);

        return redirect()->route('admin.users.index')->with('success', 'Account updated successfully!');
    }

    /**
     * Resets a manager/receptionist's password back to the shared
     * default - this is the "ask the System Administrator" path: staff
     * never self-serve a password reset, they contact the admin, who
     * resets it here, then they set a new permanent one on next login.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        abort_unless($this->isElevatedStaff($user), 404);

        $user->update(['password' => self::DEFAULT_STAFF_PASSWORD, 'failed_login_attempts' => 0]);

        // Keep the two reset paths from ever showing contradictory state -
        // this direct button is effectively an approval too, so any request
        // the user has open (see Admin\PasswordResetRequestController) is
        // resolved the same way approve() would resolve it.
        StaffPasswordResetRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->first()
            ?->update(['status' => 'approved', 'processed_by' => auth()->id(), 'processed_at' => now()]);

        Activity::log('Reset staff password', $user->full_name, $user);

        return redirect()->route('admin.users.index')
            ->with('success', 'Password reset to the default (' . self::DEFAULT_STAFF_PASSWORD . '). They\'ll be asked to set a new one on next login.');
    }

    /**
     * Deactivate is only available for manager/receptionist accounts
     * from this screen - guest accounts and the admin's own account are
     * view-only.
     */
    public function deactivate(User $user): RedirectResponse
    {
        abort_unless($this->isElevatedStaff($user), 404);

        $user->update(['status' => 'suspended']);

        Activity::log('Deactivated staff account', $user->full_name, $user);

        return redirect()->route('admin.users.index')->with('success', 'User deactivated successfully!');
    }

    public function reactivate(User $user): RedirectResponse
    {
        abort_unless($this->isElevatedStaff($user), 404);

        $user->update(['status' => 'active']);

        Activity::log('Reactivated staff account', $user->full_name, $user);

        return redirect()->route('admin.users.index')->with('success', 'User reactivated successfully!');
    }

    /** Guest accounts, manager/receptionist accounts, and the admin's own account. */
    private function isManageable(User $user): bool
    {
        return $user->role !== 'admin' || $user->id === auth()->id();
    }

    /** Manager and receptionist accounts specifically - eligible for create/edit/activate/deactivate/reset-password. */
    private function isElevatedStaff(User $user): bool
    {
        return in_array($user->role, ['manager', 'receptionist'], true);
    }

    /** lastname.role@hotel.com, lowercased and stripped of anything but letters - with a numeric suffix if that address is already taken. */
    private function generateStaffEmail(string $lastName, string $role): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z]/', '', $lastName));
        $base = "{$slug}.{$role}@hotel.com";

        if (! User::where('email', $base)->exists()) {
            return $base;
        }

        $suffix = 2;
        while (User::where('email', "{$slug}{$suffix}.{$role}@hotel.com")->exists()) {
            $suffix++;
        }

        return "{$slug}{$suffix}.{$role}@hotel.com";
    }
}