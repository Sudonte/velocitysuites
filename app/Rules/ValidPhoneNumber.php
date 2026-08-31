<?php

namespace App\Rules;

use App\Services\PhoneValidationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Thin validation-rule wrapper around PhoneValidationService so every controller keeps its
 * existing $request->validate([...]) shape: 'mobile_number' => ['required'|'nullable',
 * 'string', new ValidPhoneNumber($country)].
 */
class ValidPhoneNumber implements ValidationRule
{
    public function __construct(private readonly ?string $country)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $result = (new PhoneValidationService())->validate((string) $this->country, (string) $value);

        if (! $result['valid']) {
            $fail($result['message'] ?? 'Enter a valid mobile number.');
        }
    }
}
