<?php

namespace App\Rules;

use App\Support\EmailDomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Guest sign-up (web and app): the email must be a Gmail address (case and spaces ignored). */
class GmailAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! EmailDomain::isGmail((string) $value)) {
            $fail(EmailDomain::SIGN_UP_NOT_GMAIL);
        }
    }
}
