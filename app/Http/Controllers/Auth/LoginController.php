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

        // Check if user exists and status is active
        if (! $user || $user->status === 'suspended') {
            return back()->with('error', 'Account suspended or credentials invalid.');
        }

        // Check account lockout after 3 failed attempts
        if ($user->failed_login_attempts >= 3) {
            return back()->with('error', 'Account locked due to multiple failed login attempts.');
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
            if ($bookingIntent && isset($bookingIntent['room_id'])) {
                // Build the redirect URL with booking data
                $roomUrl = route('public.rooms.show', ['room' => $bookingIntent['room_id']]);

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

        // Increment failed login attempts
        $user->increment('failed_login_attempts');

        return back()->withInput($request->only('email'))->with('error', 'Invalid credentials.');
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
