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

        $requests = $query->latest()->paginate(15)->withQueryString();

        return view('admin.users.password-requests.index', compact('requests'));
    }

    public function approve(StaffPasswordResetRequest $staffPasswordResetRequest): RedirectResponse
    {
        abort_unless($staffPasswordResetRequest->status === 'pending', 422, 'Only a pending request can be approved.');

        $user = $staffPasswordResetRequest->user;

        $user->update([
            'password' => \App\Http\Controllers\Admin\UserManagementController::DEFAULT_STAFF_PASSWORD,
            'failed_login_attempts' => 0,
        ]);

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
            "Hi {$user->first_name},\n\nYour password reset request has been approved. Please contact the System Administrator to receive your temporary password, then log in to set a new permanent one.\n\n- VelocitySuites"
        );

        return redirect()->route('admin.users.password-requests.index')
            ->with('success', "Request approved. {$user->full_name}'s temporary password is: " . \App\Http\Controllers\Admin\UserManagementController::DEFAULT_STAFF_PASSWORD . ' - they\'ll be asked to set a new one on next login.');
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
