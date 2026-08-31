<?php

use App\Models\Amenity;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfills a real category + 2-3 sentence description (see
     * App\Rules\MeaningfulDescription) for every legacy amenity that
     * still has none, or only a fragment (< 12 words) - same
     * only-fill-if-missing approach as 2026_08_23_100000, so an
     * amenity's existing charge, stock, and status are never touched.
     */
    public function up(): void
    {
        $entries = [
            'Air Conditioning' => [
                'category' => 'Room Amenities',
                'description' => 'Every room is equipped with individually controlled air conditioning to keep guests comfortable throughout their stay. Guests can adjust the temperature to their preference at any time. This amenity is already included with the room at no additional charge.',
            ],
            'Balcony' => [
                'category' => 'Room Amenities',
                'description' => 'Select rooms feature a private balcony where guests can relax and enjoy the surrounding view. Access to the balcony is included with rooms configured to have one, at no additional charge. Availability depends on the specific room assigned.',
            ],
            'Bathtub' => [
                'category' => 'Bathroom & Toiletries',
                'description' => "Select rooms are equipped with a private bathtub in addition to the shower, offering guests a more relaxing bathing option. This amenity is already included with rooms configured to have one, at no additional charge. Availability depends on the specific room assigned.",
            ],
            'Blanket' => [
                'category' => 'Comfort & Bedding',
                'description' => 'Guests can request an additional blanket for extra warmth and comfort during their stay. The configured additional price will be applied for each blanket requested. Requests are subject to availability and hotel confirmation.',
            ],
            'Breakfast Buffet' => [
                'category' => 'Food & Beverage',
                'description' => "Guests can enjoy access to the hotel's breakfast buffet, featuring a wider selection than the standard complimentary breakfast. The configured additional price applies per guest for this upgraded dining option. Availability and serving hours are subject to the hotel's current schedule.",
            ],
            'Free WiFi' => [
                'category' => 'Internet & Technology',
                'description' => "Guests can connect to the hotel's wireless internet service at no additional charge during their stay. This amenity is available throughout guest rooms and common areas. Connection quality may vary depending on network coverage and availability.",
            ],
            'Mini Bar' => [
                'category' => 'Room Amenities',
                'description' => 'Select rooms are equipped with an in-room mini bar for guest convenience. This amenity is already included with rooms configured to have one, at no additional charge. Guests should check with the front desk regarding any items requiring separate payment.',
            ],
            'Ocean View' => [
                'category' => 'Room Amenities',
                'description' => 'Select rooms offer a scenic ocean view for guests to enjoy during their stay. This amenity is already included with rooms configured to have one, at no additional charge. Availability depends on the specific room assigned and cannot be guaranteed for every booking.',
            ],
            'Room Service' => [
                'category' => 'Guest Services',
                'description' => "Guests can request in-room dining service for their convenience during their stay. This basic room service is already included with the room at no additional charge, subject to the hotel's operating hours. Specific food and beverage orders may carry their own separate charges.",
            ],
        ];

        foreach ($entries as $name => $fields) {
            $amenity = Amenity::whereRaw('LOWER(amenity_name) = ?', [strtolower($name)])->first();

            if (! $amenity) {
                continue;
            }

            $fill = [];
            if (empty($amenity->category)) {
                $fill['category'] = $fields['category'];
            }
            if (empty($amenity->description) || str_word_count(trim($amenity->description)) < 12) {
                $fill['description'] = $fields['description'];
            }
            if ($fill) {
                $amenity->update($fill);
            }
        }
    }

    public function down(): void
    {
        // Not reversible - only ever fills in previously-missing category/
        // description text, never removes or changes existing catalog data.
    }
};
