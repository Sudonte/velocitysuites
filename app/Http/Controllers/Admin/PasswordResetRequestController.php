<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffPasswordResetRequest;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * System Administrator's queue for Manager/Receptionist password-reset
 * requests (see Auth\ForgotPasswordController's staff branch of
 * sendResetLink(), which is where these get created). Approval reuses the
 * existing shared Admin\UserManagementController::DEFAULT_STAFF_PASSWORD +
 * Auth\ForcePasswordChangeController mechanism - the same one the direct
 * "Reset Password" button on the Users list already uses - rather than a
 * separate per-request secret.
 */
class PasswordResetRequestController extends Controller
{
    public function index(Request $request): View
    {
        $query = StaffPasswordResetRequest::with(['user', 'processedBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->latest()->paginate(\App\Support\PerPage::resolve($request))->withQueryString();

        return view('admin.users.password-requests.index', compact('requests'));
    }

    public function approve(StaffPasswordResetRequest $staffPasswordResetRequest): RedirectResponse
    {
        abort_unless($staffPasswordResetRequest->status === 'pending', 422, 'Only a pending request can be approved.');

        $user = $staffPasswordResetRequest->user;
        // A password made for this one account and this one approval: random, shown once to the administrator, good for 24 hours.
        $temporaryPassword = self::temporaryPassword();

        $user->update([
            'password' => $temporaryPassword,
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addHours(self::TEMPORARY_PASSWORD_HOURS),
            'failed_login_attempts' => 0,
            'last_failed_login_at' => null,
        ]);
        $user->forceFill(['last_failed_login_at' => null])->save();

        $staffPasswordResetRequest->update([
            'status' => 'approved',
            'processed_by' => auth()->id(),
            'processed_at' => now(),
        ]);

        Activity::log(
            'Approved password reset request',
            "{$user->full_name} ({$user->role})",
            $staffPasswordResetRequest
        );

        $this->emailStatus(
            $user,
            'Your VelocitySuites password reset request has been approved',
            "Hi {$user->first_name},\n\nYour password reset request has been approved. The System Administrator will give you your temporary password (it works for 24 hours); sign in with it and set a new permanent one.\n\n- VelocitySuites"
        );

        return redirect()->route('admin.users.password-requests.index')
            ->with('success', "Request approved for {$user->full_name}.")
            ->with('temporary_password', [
                'name' => $user->full_name,
                'role' => ucfirst($user->role),
                'email' => $user->email,
                'password' => $temporaryPassword,
                'expires' => $user->temporary_password_expires_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A'),
            ]);
    }

    public const TEMPORARY_PASSWORD_HOURS = 24;

    /** 12 characters from an alphabet without look-alikes, always with a letter and a number (the shared password rule). */
    public static function temporaryPassword(): string
    {
        $letters = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $all = $letters . '23456789';
        $out = $letters[random_int(0, strlen($letters) - 1)];
        for ($i = 0; $i < 10; $i++) {
            $out .= $all[random_int(0, strlen($all) - 1)];
        }

        return $out . '23456789'[random_int(0, 7)];
    }

    public function reject(Request $request, StaffPasswordResetRequest $staffPasswordResetRequest): RedirectResponse
    {
        abort_unless($staffPasswordResetRequest->status === 'pending', 422, 'Only a pending request can be rejected.');

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $user = $staffPasswordResetRequest->user;

        $staffPasswordResetRequest->update([
            'status' => 'rejected',
            'processed_by' => auth()->id(),
            'processed_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        Activity::log(
            'Rejected password reset request',
            "{$user->full_name} ({$user->role}) - {$validated['reason']}",
            $staffPasswordResetRequest
        );

        $this->emailStatus(
            $user,
            'Your VelocitySuites password reset request was not approved',
            "Hi {$user->first_name},\n\nYour password reset request was not approved.\n\nReason: {$validated['reason']}\n\nYou may submit a new request from the login page, or contact the System Administrator directly.\n\n- VelocitySuites"
        );

        return redirect()->route('admin.users.password-requests.index')
            ->with('success', 'Request rejected.');
    }

    private function emailStatus($user, string $subject, string $body): void
    {
        try {
            Mail::raw($body, function ($message) use ($user, $subject) {
                $message->to($user->email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email password-reset-request status to {$user->email}: " . $e->getMessage());
        }
    }
}
