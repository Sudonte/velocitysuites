<?php

namespace App\Http\Controllers\Auth;

use App\Support\PasswordPolicy;

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

    public const SENT_MESSAGE = "If an account exists for that email, we've sent a code.";

    public function showLinkRequestForm()
    {
        return view('auth.forgot-password');
    }

    /** The staff reset-request form (managers and receptionists are reset by the System Administrator, not by a code). */
    public function showStaffForm()
    {
        return view('auth.forgot-password-staff');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // the same rule as the page: only a Gmail address belongs here (a hotel address is sent to the staff request)
        if ($wrong = \App\Support\EmailDomain::gmailFormError((string) $request->email)) {
            return back()->withInput()->withErrors(['email' => $wrong]);
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim((string) $request->email))])->first();

        // The same answer whether or not the address has an account (the form must not reveal which emails are registered).
        if (! $user) {
            return redirect()->route('password.otp.form', ['email' => $request->email])
                ->with('status', self::SENT_MESSAGE);
        }

        // A manager or receptionist never gets a code and the answer is the same as for an unknown address (their reset is requested
        // from the System Administrator on the staff page).
        if (in_array($user->role, ['manager', 'receptionist'], true) || $user->status !== 'active') {
            return redirect()->route('password.otp.form', ['email' => $request->email])
                ->with('status', self::SENT_MESSAGE);
        }

        if (! $this->passwordReset->sendOtp($user)) {
            return back()->withInput()->with('error', "We couldn't send the verification email right now. Please try again in a few minutes.");
        }

        return redirect()->route('password.otp.form', ['email' => $user->email])
            ->with('status', self::SENT_MESSAGE);
    }

    public const STAFF_MESSAGE = 'If this is a staff account, your request has been sent to the System Administrator.';

    /**
     * The staff reset request (Manager and Receptionist): the same confirmation for every address, so the page never says whether the
     * email has an account or what role it has. A real staff account gets one open request for the System Administrator (never a second
     * while one is outstanding) and a confirmation email that leaves after the response, so the answer takes the same time for everyone.
     */
    public function sendStaffRequest(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // only a hotel staff address (@hotel.com) belongs on this form; a Gmail address is sent back to the code reset
        if ($wrong = \App\Support\EmailDomain::staffFormError((string) $request->input('email'))) {
            return back()->withInput()->withErrors(['email' => $wrong]);
        }

        // case and surrounding spaces never matter ("Espedion.Manager@Hotel.com " is the same account)
        $email = strtolower(trim((string) $request->input('email')));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        // a request is recorded ONLY for a registered, active Receptionist or Manager account
        if ($user && $user->status === 'active' && in_array($user->role, ['manager', 'receptionist'], true)) {
            try {
                $this->handleStaffRequest($user);
            } catch (\Throwable $e) {
                // the person still gets the generic message (nothing about the account is revealed), but the failure is on record
                Log::error("Staff password reset request for user #{$user->id} could not be saved: " . $e->getMessage());
            }
        }

        return redirect()->route('password.request.staff')->with('status', self::STAFF_MESSAGE);
    }

    /**
     * Records the request so the System Administrator sees it: one open (pending) request per account - asking again only refreshes
     * its requested time - and an earlier approved request that was never used, or whose temporary password has run out, is closed as
     * "expired" so it can never hide a new one.
     */
    private function handleStaffRequest(User $user): void
    {
        $created = null;
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, &$created) {
            // an approved request still waiting for its sign-in stays "approved" only while the temporary password is valid and was not yet used
            $approved = StaffPasswordResetRequest::where('user_id', $user->id)->where('status', 'approved')->get();
            foreach ($approved as $old) {
                $stillGood = $user->must_change_password && ($user->temporary_password_expires_at === null || $user->temporary_password_expires_at->isFuture())
                    && $old->processed_at !== null && $old->processed_at->gt(now()->subHours(24));
                if (! $stillGood) {
                    $old->update(['status' => 'expired']);
                }
            }

            $pending = StaffPasswordResetRequest::where('user_id', $user->id)->where('status', 'pending')->latest('id')->first();
            if ($pending) {
                $pending->forceFill(['created_at' => now()])->save(); // keep ONE pending request and bring it to the top
                StaffPasswordResetRequest::where('user_id', $user->id)->where('status', 'pending')->where('id', '!=', $pending->id)->update(['status' => 'expired']);

                return;
            }
            $stillApproved = StaffPasswordResetRequest::where('user_id', $user->id)->where('status', 'approved')->exists();
            if ($stillApproved) {
                return; // an approved request with a live temporary password: the person should sign in with it
            }

            $created = StaffPasswordResetRequest::create(['user_id' => $user->id, 'status' => 'pending']);
        });

        if (! $created) {
            return;
        }

        \App\Support\Activity::logFor($user, 'Password reset requested', "{$user->role} asked the System Administrator for a password reset (request #{$created->id})");
        $this->notifications->notifyAdmin('Password Reset Request', "Password reset request from {$user->full_name} (" . ucfirst($user->role) . ')', 'account', $created->id);

        app()->terminating(fn () => $this->emailStaffStatus(
            $user,
            'Your VelocitySuites password reset request has been submitted',
            "Hi {$user->first_name},\n\nYour password reset request was sent to the System Administrator. You will be given a temporary password to sign in with once it is approved.\n\n- VelocitySuites"
        ));
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

    /** The old status page looked a request up by email, which told anyone whether an address was a staff account: it now just shows the same confirmation. */
    public function showStaffRequestStatus(Request $request)
    {
        return redirect()->route('password.request.staff')->with('status', self::STAFF_MESSAGE);
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

        if (! $this->passwordReset->sendOtp($user)) {
            return redirect()->route('password.otp.form', ['email' => $user->email])
                ->with('error', "We couldn't send a new verification email right now. Please try again in a few minutes.");
        }

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
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'password' => ['required', 'string', PasswordPolicy::rule(), 'confirmed'],
        ]);

        if (! $this->passwordReset->verifyOtp($validated['email'], $validated['otp'])) {
            return back()->withInput($request->only('email'))->with('error', 'Invalid or expired code.');
        }

        $user = User::where('email', $validated['email'])->first();

        $this->passwordReset->resetPassword($user, $validated['password']);
        $user->apiTokens()->delete(); // the app's sessions end with the old password

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
