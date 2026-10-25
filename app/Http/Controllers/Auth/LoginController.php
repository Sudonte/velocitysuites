<?php

namespace App\Http\Controllers\Auth;

use App\Support\PasswordPolicy;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLoginForm()
    {
        return view('auth.login');
    }

    private const FAILED_MESSAGE = 'Incorrect email or password. Check them and try again, or use "Forgot password?" to reset it.';

    private const LOCKED_MESSAGE = 'For your security, sign-in is paused for 15 minutes after 3 failed attempts in a row. Reset your password below to continue now.';

    public const SUSPENDED_MESSAGE = 'Your account has been suspended. Please contact the hotel\'s System Administrator to have it reactivated.';

    public const DEACTIVATED_MESSAGE = 'Your account is deactivated. Sign in on the Velocity Suites mobile app to reactivate it with the verification code sent to your email.';

    public const DELETED_MESSAGE = 'This account has been deleted and is no longer available.';

    /**
     * Handle login request.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user) {
            PasswordPolicy::spendCheck($credentials['password']); // about as slow as a known email with a wrong password

            return back()->withInput($request->only('email'))->with('error', self::FAILED_MESSAGE);
        }

        // A locked account still compares a few more guesses (so its owner can be told it is locked once the password is right);
        // after that the password is no longer compared at all, so a lock cannot be used to keep guessing.
        $attempts = $user->recentFailedLoginAttempts();
        $locked = $attempts >= User::LOGIN_LOCK_THRESHOLD;
        if ($attempts >= User::LOGIN_LOCK_THRESHOLD + 3) {
            PasswordPolicy::spendCheck($credentials['password']);

            return back()->withInput($request->only('email'))->with('error', self::FAILED_MESSAGE);
        }

        // Wrong password: the same message as an unknown email, whatever the account's state (no "one more attempt" warning, no
        // lock notice - those would only ever appear for real accounts).
        if (! Hash::check($credentials['password'], $user->password)) {
            $failures = $user->recordFailedLogin();
            Activity::logFor($user, 'Failed login attempt', "Wrong password (web) - {$failures} of " . User::LOGIN_LOCK_THRESHOLD);

            return back()->withInput($request->only('email'))->with('error', self::FAILED_MESSAGE);
        }

        // From here the password was right: a suspended, deactivated, deleted or locked account is told why it can't sign in.
        if ($blocked = $this->blockedMessage($user)) {
            Activity::logFor($user, 'Sign-in refused', "Account {$user->status} (web)");

            return back()->withInput($request->only('email'))->with('error', $blocked);
        }

        // Every role takes the same path to Forgot Password (Auth\ForgotPasswordController::sendResetLink() branches from there:
        // Guest/Admin get the OTP email, Manager/Receptionist a request to the System Administrator).
        if ($locked) {
            Activity::logFor($user, 'Sign-in locked', 'Sign-in attempt while locked (web)');

            return redirect()->route('password.request')
                ->with('error', self::LOCKED_MESSAGE)
                ->withInput(['email' => $credentials['email']]);
        }

        // Attempt authentication
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            // Reset failed attempts on successful login
            $user->update([
                'failed_login_attempts' => 0,
                'last_login_at' => now(),
            ]);
            Activity::logFor($user, 'Logged in', 'Signed in on the website');

            $request->session()->regenerate();

            // Get the user's role
            $role = auth()->user()->role;

            // Still on the shared default password (set at account creation
            // or by an admin's reset) - make them choose a permanent one
            // before they can reach any dashboard.
            if (auth()->user()->must_change_password) {
                return redirect()->route('force-password-change.show');
            }

            // PRIORITY: Role-based redirect always takes precedence
            // Admin, Manager, and Receptionist should go to their dashboards
            // Only Guest accounts should continue the booking process
            if ($role !== 'guest') {
                // Clear any booking intent for non-guest users
                session()->forget('booking_intent');

                return redirect()->to($this->getRedirectPath($role))
                    ->with('success', 'Login successful! Welcome back.');
            }

            // For Guest users, check for booking intent and redirect to booking flow
            $bookingIntent = session()->get('booking_intent');
            if ($bookingIntent && isset($bookingIntent['room_type_id'])) {
                // Build the redirect URL with booking data
                $roomUrl = route('public.rooms.show', ['roomType' => $bookingIntent['room_type_id']]);

                // Add query parameters for pre-filled booking data
                $queryParams = [];
                if (!empty($bookingIntent['check_in'])) {
                    $queryParams['check_in'] = $bookingIntent['check_in'];
                }
                if (!empty($bookingIntent['check_out'])) {
                    $queryParams['check_out'] = $bookingIntent['check_out'];
                }
                if (!empty($bookingIntent['guests'])) {
                    $queryParams['guests'] = $bookingIntent['guests'];
                }

                // Clear the booking intent after using it
                session()->forget('booking_intent');

                if (!empty($queryParams)) {
                    $roomUrl .= '?' . http_build_query($queryParams);
                }

                return redirect($roomUrl)->with('success', 'Login successful! Continue with your booking.');
            }

            // No booking intent - redirect to Guest Dashboard
            return redirect()->to($this->getRedirectPath('guest'))
                ->with('success', 'Login successful! Welcome back.');
        }

        // The password was just confirmed above, so reaching here means the session could not be started: treat it as a failed attempt.
        return back()->withInput($request->only('email'))->with('error', self::FAILED_MESSAGE);
    }

    /**
     * Why this account can't sign in, or null when it can. A guest inside the 30-day
     * restore window still signs in - CheckAccountStatus routes them to the restore prompt.
     */
    private function blockedMessage(User $user): ?string
    {
        return match (true) {
            $user->status === 'suspended' => self::SUSPENDED_MESSAGE,
            $user->status === 'deactivated' => self::DEACTIVATED_MESSAGE,
            $user->isPendingDeletion() && ! $user->isRestorable() => self::DELETED_MESSAGE,
            default => null,
        };
    }

    /**
     * Get redirect route based on user role.
     */
    private function getRedirectPath($role)
    {
        return match ($role) {
            'admin' => route('admin.dashboard'),
            'manager' => route('manager.dashboard'),
            'receptionist' => route('receptionist.dashboard'),
            'guest' => route('guest.dashboard'),
            default => route('home'),
        };
    }

    /**
     * Handle logout.
     */
    public function logout(Request $request)
    {
        if ($user = $request->user()) {
            Activity::logFor($user, 'Logged out', 'Signed out of the website');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Always redirect to landing page after logout
        return redirect()->route('home')->with('success', 'Logged out successfully.');
    }
}
