<?php

namespace App\Support;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The bearer token the guest web pages use to call the same /api/* endpoints the Android app uses. One per web session
 * (device_name "Web"), valid for the life of the session, revoked on sign-out. The app's own tokens are never touched.
 */
final class PortalToken
{
    public const DEVICE = 'Web';

    private const LIFETIME_HOURS = 12;

    /** The plain token for the signed-in guest's web session (created on first use); null for anyone else. */
    public static function plain(): ?string
    {
        $user = auth()->user();
        if (! $user || $user->role !== 'guest') {
            return null;
        }

        try {
            return self::forUser($user);
        } catch (\Throwable $e) {
            // a page must still draw if the token cannot be made; its calls then ask the guest to sign in again
            \Illuminate\Support\Facades\Log::warning('Portal token unavailable: '.$e->getMessage());

            return null;
        }
    }

    private static function forUser(User $user): string
    {

        $existing = session('portal_token');
        if (is_string($existing)) {
            $row = ApiToken::where('token', hash('sha256', $existing))->where('user_id', $user->id)->first();
            if ($row && (! $row->expires_at || $row->expires_at->isFuture())) {
                // slide the window while the guest is active
                if (! $row->expires_at || $row->expires_at->diffInMinutes(now()) < (self::LIFETIME_HOURS * 60 - 30)) {
                    $row->update(['expires_at' => now()->addHours(self::LIFETIME_HOURS)]);
                }

                return $existing;
            }
        }

        // housekeeping: this guest's expired web tokens
        ApiToken::where('user_id', $user->id)->where('device_name', self::DEVICE)->where('expires_at', '<', now())->delete();

        return self::issue($user);
    }

    /** Adopt a token the API just issued (sign-in on the web): it becomes this session's token. */
    public static function adopt(string $plain, User $user): void
    {
        ApiToken::where('token', hash('sha256', $plain))->where('user_id', $user->id)->update([
            'device_name' => self::DEVICE,
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ]);
        session(['portal_token' => $plain]);
    }

    /** Sign-out: this session's token stops working. */
    public static function revoke(): void
    {
        $plain = session('portal_token');
        if (is_string($plain)) {
            ApiToken::where('token', hash('sha256', $plain))->delete();
        }
        session()->forget('portal_token');
    }

    private static function issue(User $user): string
    {
        $plain = Str::random(60);
        ApiToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plain),
            'device_name' => self::DEVICE,
            'last_used_at' => now(),
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ]);
        session(['portal_token' => $plain]);

        return $plain;
    }
}
