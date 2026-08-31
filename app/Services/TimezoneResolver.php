<?php

namespace App\Services;

/**
 * Resolves the authoritative IANA timezone for a country - the server, never the client,
 * decides what actually gets stored. For the handful of countries that genuinely span
 * multiple timezones, a client-submitted value is only honored if it's actually one of that
 * country's own known zones; every other supported country maps to a single fixed zone
 * regardless of what was submitted. Shared by RegisterController, ProfileController, and
 * Guest\GuestController so the same rules apply everywhere a country is picked, and kept in
 * sync with the same two lists used by the web (address-cascade.js) and Android
 * (AddressHierarchyController) pickers.
 */
class TimezoneResolver
{
    private const MULTI_TIMEZONE_COUNTRIES = [
        'United States' => ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu'],
        'Canada' => ['America/St_Johns', 'America/Halifax', 'America/Toronto', 'America/Winnipeg', 'America/Edmonton', 'America/Vancouver'],
        'Australia' => ['Australia/Sydney', 'Australia/Brisbane', 'Australia/Adelaide', 'Australia/Darwin', 'Australia/Perth'],
    ];

    private const SINGLE_TIMEZONE_COUNTRIES = [
        'Philippines' => 'Asia/Manila',
        'United Kingdom' => 'Europe/London',
        'Singapore' => 'Asia/Singapore',
        'Malaysia' => 'Asia/Kuala_Lumpur',
        'Japan' => 'Asia/Tokyo',
        'South Korea' => 'Asia/Seoul',
        'United Arab Emirates' => 'Asia/Dubai',
        'Saudi Arabia' => 'Asia/Riyadh',
        'Qatar' => 'Asia/Qatar',
        'Hong Kong' => 'Asia/Hong_Kong',
        'New Zealand' => 'Pacific/Auckland',
    ];

    public function resolve(string $country, ?string $submitted): ?string
    {
        if (isset(self::MULTI_TIMEZONE_COUNTRIES[$country])) {
            return in_array($submitted, self::MULTI_TIMEZONE_COUNTRIES[$country], true) ? $submitted : null;
        }

        return self::SINGLE_TIMEZONE_COUNTRIES[$country] ?? null;
    }
}
