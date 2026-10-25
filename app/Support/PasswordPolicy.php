<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The one rule for a NEW password anywhere (sign-up, reset, change, the web forms): 8 or more characters with at least one letter and one
 * number. It is checked only when a password is being set; an existing password is never re-judged, so everyone can still sign in.
 * The Android app shows the same checklist (PasswordRules).
 */
final class PasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(8)->letters()->numbers();
    }

    /** Validation messages for the password field {@code $field} (the Laravel defaults say "must contain at least one letter"). */
    public static function messages(string $field = 'password'): array
    {
        return [
            "$field.min" => 'The password must be at least 8 characters.',
            "$field.letters" => 'The password must contain at least one letter.',
            "$field.numbers" => 'The password must contain at least one number.',
        ];
    }

    /** The HTML pattern the web forms use for the same rule. */
    public const HTML_PATTERN = '(?=.*[A-Za-z])(?=.*[0-9]).{8,}';

    public const HTML_TITLE = 'At least 8 characters with a letter and a number';

    /**
     * Spends the time of one real password check on a made-up hash, so an unknown email takes about as long to answer as a known one with
     * a wrong password (the sign-in answer must not tell them apart).
     */
    public static function spendCheck(string $password): void
    {
        $hash = Cache::store('file')->rememberForever('auth:timing-hash', fn () => Hash::make(Str::random(40)));
        Hash::check($password, $hash);
    }
}
