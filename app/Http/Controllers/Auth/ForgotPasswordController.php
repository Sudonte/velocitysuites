<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StaffPasswordResetRequest;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Self-service password reset for Guest/Admin (6-digit emailed OTP, mirrors
 * Api\AuthController::forgotPassword/verifyResetOtp/resetPassword - same
 * PasswordResetService, same password_reset_tokens table, same 15-minute
 * window) and a request/approval flow for Manager/Receptionist, who can't
 * verify an OTP against an email the System Administrator controls the
 * same way a guest owns theirs - they instead submit a
 * StaffPasswordResetRequest that shows up for the admin to approve
 * (see Admin\PasswordResetRequestController), which resets them onto the
 * existing shared DEFAULT_STAFF_PASSWORD + forces a real password choice
 * via Auth\ForcePasswordChangeController, same as account creation.
 *
 * One shared "Forgot Password?" entry point for every role - sendResetLink()
 * branches internally rather than staff having a separate link/route.
 *
 * Guest/Admin flow: email entry (sendResetLink) -> OTP verify screen
 * (showOtpForm/verifyOtp/resendOtp) -> reset-password screen
 * (showResetForm/reset).
 * Manager/Receptionist flow: email entry (sendResetLink) -> status page
 * (showStaffRequestStatus), no OTP - admin approval instead.
 */
class ForgotPasswordController extends Controller
{
    public function __construct(
        private PasswordResetService $passwordReset,
        private NotificationService $notifications,
    ) {
    }

    public function showLinkRequestForm()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users']);

        $user = User::where('email', $request->email)->first();

        if (in_array($user->role, ['manager', 'receptionist'], true)) {
            return $this->handleStaffRequest($user);
        }

        $this->passwordReset->sendOtp($user);

        return redirect()->route('password.otp.form', ['email' => $user->email])
            ->with('status', 'A verification code has been sent to your email.');
    }

    /**
     * Creates (or reuses, if one is already outstanding) a
     * StaffPasswordResetRequest and sends the admin to their queue -
     * never creates a second open request for the same user, so
     * resubmitting the form just re-shows the existing request's status.
     */
    private function handleStaffRequest(User $user)
    {
        $existing = StaffPasswordResetRequest::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->latest()
            ->first();

        $needsNewRequest = ! $existing || ($existing->status === 'pending' && $existing->isExpired());

        if ($needsNewRequest) {
            StaffPasswordResetRequest::create(['user_id' => $user->id, 'status' => 'pending']);

            $this->notifications->notifyAdmin(
                'Password Reset Request',
                "{$user->full_name} ({$user->role}) requested a password reset.",
                'account'
            );

            $this->emailStaffStatus(
                $user,
                'Your VelocitySuites password reset request has been submitted',
                "Hi {$user->first_name},\n\nYour password reset request has been submitted and is awaiting System Administrator approval. You'll be able to log in with a temporary password once it's approved - please check back or contact the System Administrator directly.\n\n- VelocitySuites"
            );
        }

        return redirect()->route('password.staff-request.status', ['email' => $user->email])
            ->with('status', $needsNewRequest
                ? 'Your password reset request has been submitted to the System Administrator.'
                : 'You already have a password reset request in progress.');
    }

    private function emailStaffStatus(User $user, string $subject, string $body): void
    {
        try {
            Mail::raw($body, function ($message) use ($user, $subject) {
                $message->to($user->email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email staff password-reset status to {$user->email}: " . $e->getMessage());
        }
    }

    /**
     * Status page for a Manager/Receptionist's password reset request -
     * reachable while logged out, looked up by the email they submitted
     * (same ?email= query-param convention the OTP flow already uses).
     */
    public function showStaffRequestStatus(Request $request)
    {
        $user = User::where('email', $request->query('email'))
            ->whereIn('role', ['manager', 'receptionist'])
            ->first();

        $resetRequest = $user
            ? StaffPasswordResetRequest::where('user_id', $user->id)->latest()->first()
            : null;

        return view('auth.staff-reset-status', [
            'email' => $request->query('email'),
            'resetRequest' => $resetRequest,
        ]);
    }

    public function showOtpForm(Request $request)
    {
        return view('auth.verify-reset-otp', ['email' => $request->query('email')]);
    }

    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users',
            'otp' => 'required|string|size:6',
        ]);

        if (! $this->passwordReset->verifyOtp($validated['email'], $validated['otp'])) {
            return back()->withInput($request->only('email'))->withErrors(['otp' => 'Invalid or expired code.']);
        }

        // One-shot flash data, not a persistent session flag - reset()
        // still re-validates the OTP itself server-side regardless, so
        // this only saves the guest from retyping it on the next screen.
        return redirect()->route('password.reset', ['email' => $validated['email']])
            ->with('verified_otp', $validated['otp']);
    }

    public function resendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users']);

        $user = User::where('email', $request->email)->first();

        if (in_array($user->role, ['manager', 'receptionist'], true)) {
            return back()->withInput()->with('error', 'Managers and receptionists don\'t self-reset - please contact the System Administrator to reset your password.');
        }

        $this->passwordReset->sendOtp($user);

        return redirect()->route('password.otp.form', ['email' => $user->email])
            ->with('status', 'A new verification code has been sent to your email.');
    }

    public function showResetForm(Request $request)
    {
        return view('auth.reset-password', [
            'email' => $request->query('email'),
            'otp' => session('verified_otp'),
        ]);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users',
            'otp' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (! $this->passwordReset->verifyOtp($validated['email'], $validated['otp'])) {
            return back()->withInput($request->only('email'))->with('error', 'Invalid or expired code.');
        }

        $user = User::where('email', $validated['email'])->first();

        $this->passwordReset->resetPassword($user, $validated['password']);

        // Upon successful reset, sign them straight in and take them to
        // their dashboard rather than back to the login form.
        auth()->login($user);
        $request->session()->regenerate();

        return redirect()->to($this->dashboardFor($user->role))
            ->with('success', 'Password reset successfully. Welcome back!');
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
