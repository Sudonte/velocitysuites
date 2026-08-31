<?php

use App\Models\Amenity;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Configures the 8 example amenities from the Amenities module spec,
     * each with a real category and a 2-3 sentence description (see
     * App\Rules\MeaningfulDescription - the same rule now enforced on the
     * admin CRUD form). Matched by exact case-insensitive amenity_name:
     * - If an amenity with that name already exists (e.g. "Extra Bed" was
     *   already configured in an earlier session), only its category/
     *   description are filled in when missing - its existing price,
     *   stock, and active/inactive status are left untouched, since those
     *   are real admin-configured values this migration must not silently
     *   overwrite.
     * - If it doesn't exist yet, it's created fresh with the price/status
     *   the spec describes.
     */
    public function up(): void
    {
        $entries = [
            [
                'amenity_name' => 'Complimentary Breakfast',
                'category' => 'Food & Beverage',
                'description' => 'Eligible guests can enjoy a complimentary breakfast during their stay at Velocity Suites. This breakfast is already included with the applicable room booking at no additional charge. Please check with the front desk for the configured serving schedule and any applicable conditions.',
                'charge' => 0,
                'quantity' => 999,
                'status' => 'active',
            ],
            [
                'amenity_name' => 'Free Wi-Fi Access',
                'category' => 'Internet & Technology',
                'description' => "Guests can access the hotel's wireless internet throughout their stay without any additional fee. Free Wi-Fi is available in guest rooms and common areas for browsing, streaming, and staying connected. Connection quality may vary depending on network coverage and overall availability.",
                'charge' => 0,
                'quantity' => 999,
                'status' => 'active',
            ],
            [
                'amenity_name' => 'Spacious and Secured Parking Area',
                'category' => 'Parking & Transportation',
                'description' => "Guests can use the hotel's on-site parking facility throughout their stay at no additional charge. The parking area is designed to provide convenient and secure parking for guest vehicles. Availability is subject to the hotel's configured parking capacity and rules.",
                'charge' => 0,
                'quantity' => 999,
                'status' => 'active',
            ],
            [
                'amenity_name' => 'Additional Towel',
                'category' => 'Bathroom & Toiletries',
                'description' => 'Guests can request additional towels beyond the standard room allocation at any time during their stay. An additional configured fee will apply for every extra towel requested. Please specify the desired quantity when submitting the request.',
                'charge' => 50,
                'quantity' => 50,
                'status' => 'active',
            ],
            [
                'amenity_name' => 'Additional Pillow',
                'category' => 'Comfort & Bedding',
                'description' => 'Guests can request additional pillows for extra comfort during their stay. A configured additional charge applies based on the quantity selected. This can be requested during booking or afterward through the Additional Amenity Request feature, subject to availability.',
                'charge' => 50,
                'quantity' => 50,
                'status' => 'active',
            ],
            [
                'amenity_name' => 'Additional Blanket',
                'category' => 'Comfort & Bedding',
                'description' => 'Guests can request an additional blanket whenever they require extra bedding for comfort or warmth. The configured additional price will be applied according to the quantity selected. Requests are subject to availability and hotel confirmation.',
                'charge' => 100,
                'quantity' => 30,
                'status' => 'active',
            ],
            [
                'amenity_name' => 'Extra Bed',
                'category' => 'Comfort & Bedding',
                'description' => "Guests can request an extra bed when the selected room supports an additional sleeping arrangement. The additional charge and availability are subject to the hotel's current configuration and the room's capacity. Please confirm availability with the front desk before booking.",
                'charge' => 500,
                'quantity' => 20,
                'status' => 'active',
            ],
            [
                'amenity_name' => 'Laundry Service',
                'category' => 'Housekeeping & Laundry',
                'description' => 'Guests can request laundry services for eligible clothing or personal items during their stay. The applicable service price and any quantity rules will be displayed before the request is confirmed. Turnaround time may vary depending on current hotel operations.',
                'charge' => 150,
                'quantity' => 999,
                'status' => 'active',
            ],
        ];

        foreach ($entries as $entry) {
            $existing = Amenity::whereRaw('LOWER(amenity_name) = ?', [strtolower($entry['amenity_name'])])->first();

            if ($existing) {
                $fill = [];
                if (empty($existing->category)) {
                    $fill['category'] = $entry['category'];
                }
                if (empty($existing->description) || str_word_count(trim($existing->description)) < 8) {
                    $fill['description'] = $entry['description'];
                }
                if ($fill) {
                    $existing->update($fill);
                }
                continue;
            }

            Amenity::create($entry);
        }
    }

    public function down(): void
    {
        // Intentionally not reversible - this migration only ever fills in
        // missing category/description on existing rows or creates new
        // catalog rows; a blind down() would risk deleting real amenities
        // that have since accrued live reservation_amenities/amenity_requests
        // history.
    }
};
