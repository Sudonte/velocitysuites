<?php

namespace App\Support;

use App\Models\Guest;
use App\Models\User;

/**
 * Optional "Guest account" link on the receptionist's Create Reservation,
 * Create Booking and Walk-in Check-in forms: when the receptionist picks an
 * existing guest account, the stay is saved with that guest's guest_id, so
 * it shows on the guest's website and app and they get notifications.
 * Nothing picked = a typed-name walk-in, exactly as before.
 */
final class GuestAccountLink
{
    /** Validation rule for the hidden guest_user_id field the form posts. */
    public static function rules(): array
    {
        return ['guest_user_id' => 'nullable|integer|exists:users,id'];
    }

    /** The linked guest profile, or null - only an active guest account counts. */
    public static function resolve(mixed $userId): ?Guest
    {
        if (! $userId) {
            return null;
        }

        $user = User::where('role', 'guest')->where('status', 'active')->find((int) $userId);

        return $user?->guest;
    }

    /** Guest accounts matching an email / name fragment, for the lookup box. */
    public static function search(string $term, int $limit = 8): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return User::where('role', 'guest')->where('status', 'active')->whereHas('guest')
            ->where(fn ($q) => $q->where('email', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%"))
            ->with('guest')
            ->orderBy('first_name')->limit($limit)->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'email' => $u->email,
                'first_name' => $u->first_name,
                'middle_name' => $u->middle_name,
                'last_name' => $u->last_name,
                'name' => $u->full_name,
                'mobile' => $u->guest?->mobile_number,
            ])->all();
    }
}
