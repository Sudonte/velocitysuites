<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Reservation;
use App\Services\ReservationAmenityService;
use Illuminate\Validation\ValidationException;

/**
 * Amenities are Limited (quantity is stock) or Unlimited (never refused for
 * stock). Existing amenities stay Limited.
 */
class UnlimitedAmenityTest extends ApiFlowTestCase
{
    private function amenity(array $overrides = []): Amenity
    {
        return Amenity::create(array_merge([
            'amenity_name' => 'Extra Towel ' . uniqid(), 'description' => 'Soft towel', 'category' => 'Bathroom & Toiletries',
            'quantity' => 2, 'charge' => 50, 'status' => 'active',
        ], $overrides));
    }

    public function test_limited_amenity_refuses_more_than_remaining_stock(): void
    {
        $amenity = $this->amenity();

        $this->expectException(ValidationException::class);
        app(ReservationAmenityService::class)->validateSelection([['amenity_id' => $amenity->id, 'quantity' => 3]]);
    }

    public function test_unlimited_amenity_is_never_refused_and_keeps_reporting_unlimited(): void
    {
        $amenity = $this->amenity(['is_unlimited' => true, 'quantity' => 0]);
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);
        $reservation = Reservation::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => now()->addDays(5), 'check_out' => now()->addDays(6),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1, 'status' => Reservation::STATUS_AWAITING_CASH,
        ]);
        AmenityRequest::create([
            'reservation_id' => $reservation->id, 'amenity_id' => $amenity->id, 'amenity_name' => $amenity->amenity_name,
            'quantity' => 40, 'charge' => 50, 'status' => 'approved',
        ]);

        $resolved = app(ReservationAmenityService::class)->validateSelection([['amenity_id' => $amenity->id, 'quantity' => 500]]);

        $this->assertSame(500, $resolved->first()['quantity']);
        $this->assertTrue(Amenity::isUnlimitedStock(Amenity::remainingStockFor([$amenity->id])[$amenity->id]));
        $this->assertSame('Unlimited', $amenity->stock_label);
    }

    public function test_api_catalog_reports_unlimited_flag_and_a_positive_quantity(): void
    {
        $amenity = $this->amenity(['is_unlimited' => true, 'quantity' => 0]);
        $row = collect($this->getJson('/api/amenities')->assertOk()->json())
            ->firstWhere('id', $amenity->id);

        $this->assertTrue((bool) $row['is_unlimited']);
        $this->assertGreaterThan(0, $row['quantity']);
    }
}
