<?php

namespace App\Services;

use libphonenumber\PhoneNumberUtil;

/**
 * Single source of truth for mobile-number validation, shared by web registration
 * (Auth\RegisterController), web guest profile (Guest\GuestController), and the mobile app's
 * API (Api\AuthController, Api\ProfileController) - previously each of those four duplicated
 * its own "$isPhilippines ? one regex : a generic 7-15-digit regex" check.
 *
 * Philippines keeps this app's own long-standing exact-shape rule (09XXXXXXXXX or
 * +639XXXXXXXXX) rather than switching it to libphonenumber's isValidNumberForRegion() -
 * direct testing against this library (giggsey/libphonenumber-for-php) confirmed it's
 * measurably more permissive than that: it accepts "08171234567" (a real PH landline shape,
 * not a mobile number) and "+6309171234567" (a stray "0" kept after the +63 country code)
 * as valid PH numbers, both of which this app has always deliberately rejected. Every other
 * supported country genuinely had no real per-country validation before (just the same
 * generic regex regardless of country) - those now use the real library, which is where it
 * actually adds value over hand-written regexes.
 */
class PhoneValidationService
{
    private const PH_PATTERN = '/^(09|\+639|639)\d{9}$/';
    private const FALLBACK_PATTERN = '/^\+?\d{7,15}$/';

    /** Every named country except Philippines (kept above) and "Other" (no fixed region -
     *  falls back to the generic pattern below). */
    private const COUNTRY_TO_REGION = [
        'United States' => 'US',
        'Canada' => 'CA',
        'United Kingdom' => 'GB',
        'Australia' => 'AU',
        'Singapore' => 'SG',
        'Malaysia' => 'MY',
        'Japan' => 'JP',
        'South Korea' => 'KR',
        'United Arab Emirates' => 'AE',
        'Saudi Arabia' => 'SA',
        'Qatar' => 'QA',
        'Hong Kong' => 'HK',
        'New Zealand' => 'NZ',
    ];

    /**
     * @return array{valid: bool, message: ?string}
     */
    public function validate(string $country, string $number): array
    {
        $number = trim($number);
        $isPhilippines = strcasecmp($country, 'Philippines') === 0;

        if ($isPhilippines) {
            $valid = (bool) preg_match(self::PH_PATTERN, $number);

            return [
                'valid' => $valid,
                'message' => $valid ? null : 'Enter a valid Philippine mobile number using 09XXXXXXXXX or +639XXXXXXXXX.',
            ];
        }

        $region = self::COUNTRY_TO_REGION[$country] ?? null;
        if ($region === null) {
            $valid = (bool) preg_match(self::FALLBACK_PATTERN, $number);

            return [
                'valid' => $valid,
                'message' => $valid ? null : 'Enter a valid mobile number with country code.',
            ];
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse($number, $region);
            $valid = $util->isValidNumberForRegion($parsed, $region);
        } catch (\Throwable $e) {
            $valid = false;
        }

        return [
            'valid' => $valid,
            'message' => $valid ? null : 'Enter a valid mobile number for the selected country.',
        ];
    }
}
