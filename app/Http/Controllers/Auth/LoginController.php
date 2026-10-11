<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
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

    private const FAILED_MESSAGE = 'The email or password you entered is incorrect. Check them and try again, or use "Forgot password?" to reset it.';

    private const LOCKED_MESSAGE = 'For your security, sign-in is paused for 15 minutes after 3 failed attempts in a row. Reset your password below to continue now.';

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

        // Unknown email, wrong password and a suspended account all get the
        // same message, so the login form never reveals whether an account exists.
        if (! $user || $user->status === 'suspended') {
            return back()->withInput($request->only('email'))->with('error', self::FAILED_MESSAGE);
        }

        // Check account lockout after 3 failed attempts - every role takes
        // the same path to Forgot Password now. Auth\ForgotPasswordController::
        // sendResetLink() branches internally from there: Guest/Admin get the
        // existing OTP-email flow, Manager/Receptionist get a
        // StaffPasswordResetRequest routed to the System Administrator for
        // approval (see Admin\PasswordResetRequestController).
        if ($user->isLoginLocked()) {
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

        // Increment failed login attempts, then immediately re-check the
        // threshold within THIS SAME request - the pre-check above only
        // ever sees the count from a PRIOR request, so without this the
        // account doesn't actually lock until a 4th attempt instead of the
        // intended 3rd.
        $failures = $user->recordFailedLogin();

        if ($failures >= User::LOGIN_LOCK_THRESHOLD) {
            return redirect()->route('password.request')
                ->with('error', self::LOCKED_MESSAGE)
                ->withInput(['email' => $credentials['email']]);
        }

        $message = $failures === User::LOGIN_LOCK_THRESHOLD - 1
            ? self::FAILED_MESSAGE . ' One more failed attempt will lock sign-in until you reset your password.'
            : self::FAILED_MESSAGE;

        return back()->withInput($request->only('email'))->with('error', $message);
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
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Always redirect to landing page after logout
        return redirect()->route('home')->with('success', 'Logged out successfully.');
    }
}
