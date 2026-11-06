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

        // Pending requests are what needs the administrator: they are the default view and always sort to the top
        $status = $request->has('status') ? (string) $request->get('status') : 'pending';
        if ($status !== '') {
            $query->where('status', $status);
        }
        $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('created_at')->orderByDesc('id');

        $requests = $query->paginate(\App\Support\PerPage::resolve($request))->withQueryString();
        $pendingCount = StaffPasswordResetRequest::where('status', 'pending')->count();

        return view('admin.users.password-requests.index', compact('requests', 'status', 'pendingCount'));
    }

    public function approve(StaffPasswordResetRequest $staffPasswordResetRequest): RedirectResponse
    {
        abort_unless($staffPasswordResetRequest->status === 'pending', 422, 'Only a pending request can be approved.');

        $user = $staffPasswordResetRequest->user;
        // The hotel's temporary password (the same one a new staff account starts with): good for 24 hours, and the first sign-in must replace it.
        $temporaryPassword = UserManagementController::DEFAULT_STAFF_PASSWORD;

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
            ->with('success', "Approved. Temporary password: {$temporaryPassword} (valid for 24 hours; must be changed at first login).")
            ->with('temporary_password', [
                'name' => $user->full_name,
                'role' => ucfirst($user->role),
                'email' => $user->email,
                'password' => $temporaryPassword,
                'expires' => $user->temporary_password_expires_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A'),
            ]);
    }

    public const TEMPORARY_PASSWORD_HOURS = 24;

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
