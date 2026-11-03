<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Support\PortalToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Turns a sign-in that the guest web pages made through the app's own API (POST /api/login, /api/verify-otp, /api/reset-password)
 * into a web session. The credentials were already checked by the API - this only trades the token it issued for a session, so
 * every sign-in rule (one "Incorrect email or password" answer, lock, deactivation, ...) is the API's, not a second copy.
 */
class PortalSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $plain = (string) $request->input('token', '');
        $row = $plain === '' ? null : ApiToken::where('token', hash('sha256', $plain))->first();
        $user = $row?->user;

        if (! $user || $user->role !== 'guest' || in_array($user->status, ['suspended', 'deactivated'], true) || ! $user->guest) {
            return response()->json(['message' => 'Sign-in could not be completed. Please sign in again.'], 401);
        }

        Auth::login($user);
        $request->session()->regenerate();
        PortalToken::adopt($plain, $user);

        return response()->json(['redirect' => session()->pull('url.intended', route('guest.dashboard'))]);
    }
}
