<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side counterpart to the client-side cascading address pickers (web JS
 * AddressCascade, Android AddressHierarchyController): verifies a submitted
 * Philippines address actually forms a valid Region -> Province -> City ->
 * Barangay chain against the same public PSGC API both clients already use,
 * so a hand-crafted API request can't submit a mismatched combination (e.g. a
 * real region name paired with a province that doesn't belong to it).
 *
 * Any country other than Philippines is free-text on both clients and isn't
 * checked here - there's no reference dataset to validate against.
 *
 * If the PSGC API itself is unreachable, validation is skipped (not failed) -
 * an unrelated third-party outage shouldn't block every registration/profile
 * update; the existing required-field checks still apply regardless.
 */
class PsgcHierarchyValidator
{
    private const BASE = 'https://psgc.gitlab.io/api/';
    private const CACHE_TTL_MINUTES = 1440;

    /**
     * @param array{country: ?string, region: ?string, province: ?string, city: ?string, barangay: ?string} $address
     * @return array<string, string> field => error message, empty when valid (or skipped)
     */
    public function validate(array $address): array
    {
        $country = trim((string) ($address['country'] ?? ''));
        if (strcasecmp($country, 'Philippines') !== 0) {
            return [];
        }

        $regions = $this->fetch('regions/');
        if ($regions === null) {
            Log::warning('PsgcHierarchyValidator: regions lookup unavailable, skipping hierarchy validation.');
            return [];
        }

        $region = $this->findByName($regions, $address['region'] ?? '');
        if (! $region) {
            return ['region' => 'Please select a valid region.'];
        }

        $provinces = $this->fetch("regions/{$region['code']}/provinces/") ?? [];
        $provinceName = trim((string) ($address['province'] ?? ''));

        if (empty($provinces)) {
            // Provinceless region (e.g. NCR) - province must be blank, cities load from the region directly.
            $cities = $this->fetch("regions/{$region['code']}/cities-municipalities/");
        } else {
            $province = $this->findByName($provinces, $provinceName);
            if (! $province) {
                return ['province' => 'Please select a province that belongs to the selected region.'];
            }
            $cities = $this->fetch("provinces/{$province['code']}/cities-municipalities/");
        }

        if ($cities === null) {
            Log::warning('PsgcHierarchyValidator: cities lookup unavailable, skipping remaining hierarchy validation.');
            return [];
        }

        $city = $this->findByName($cities, $address['city'] ?? '');
        if (! $city) {
            return ['city' => 'Please select a city/municipality that belongs to the selected province.'];
        }

        $barangayName = trim((string) ($address['barangay'] ?? ''));
        if ($barangayName === '') {
            return ['barangay' => 'Please select a barangay.'];
        }

        $barangays = $this->fetch("cities-municipalities/{$city['code']}/barangays/");
        if ($barangays === null) {
            Log::warning('PsgcHierarchyValidator: barangays lookup unavailable, skipping barangay validation.');
            return [];
        }

        if (! $this->findByName($barangays, $barangayName)) {
            return ['barangay' => 'Please select a barangay that belongs to the selected city/municipality.'];
        }

        return [];
    }

    /** @return array<int, array{code: string, name: string}>|null null on fetch failure */
    private function fetch(string $path): ?array
    {
        $cacheKey = 'psgc.' . md5($path);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($path) {
            try {
                $response = Http::timeout(5)->get(self::BASE . $path);
                if (! $response->successful()) {
                    return null;
                }
                return $response->json();
            } catch (\Throwable $e) {
                Log::warning("PsgcHierarchyValidator: request to {$path} failed: " . $e->getMessage());
                return null;
            }
        });
    }

    /** @param array<int, array{code: string, name: string}> $list */
    private function findByName(array $list, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        foreach ($list as $item) {
            if (isset($item['name']) && strcasecmp($item['name'], $name) === 0) {
                return $item;
            }
        }
        return null;
    }
}
