<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Notification;
use App\Models\User;

/**
 * Remaining stock drives the admin alerts: a request that leaves an amenity
 * at/below its low-stock level (default 10) notifies the admin once per day,
 * and an amenity with nothing left reports as out of stock.
 */
class AmenityLowStockTest extends ApiFlowTestCase
{
    private function admin(): User
    {
        return User::create([
            'first_name' => 'A', 'last_name' => 'Admin', 'email' => 'admin-stock@example.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function request(Amenity $amenity, int $qty): AmenityRequest
    {
        return AmenityRequest::create([
            'amenity_id' => $amenity->id, 'amenity_name' => $amenity->amenity_name, 'category' => 'General',
            'quantity' => $qty, 'charge' => 100, 'status' => 'pending', 'origin' => 'booking',
        ]);
    }

    public function test_low_stock_alert_once_per_day_and_out_of_stock_state(): void
    {
        $this->admin();
        $amenity = Amenity::create(['amenity_name' => 'Extra Bed', 'category' => 'General', 'quantity' => 12, 'is_unlimited' => false, 'charge' => 100, 'status' => 'active']);

        $this->request($amenity, 1); // 11 left - above the default 10, no alert
        $this->assertSame(0, Notification::where('title', 'Low Stock: Extra Bed')->count());

        $this->request($amenity, 2); // 9 left - alert
        $this->request($amenity, 1); // 8 left - same day, no second alert
        $this->assertSame(1, Notification::where('title', 'Low Stock: Extra Bed')->count());

        $remaining = Amenity::remainingStockFor([$amenity->id])[$amenity->id];
        $this->assertSame(8, $remaining);
        $this->assertSame('low', $amenity->stockState($remaining));
        $this->assertSame('out', $amenity->stockState(0));
    }

    public function test_per_amenity_level_overrides_the_default(): void
    {
        $this->admin();
        $amenity = Amenity::create(['amenity_name' => 'Towel', 'category' => 'General', 'quantity' => 50, 'is_unlimited' => false, 'charge' => 0, 'status' => 'active', 'low_stock_threshold' => 3]);

        $this->request($amenity, 40); // 10 left - above this amenity's level of 3
        $this->assertSame(0, Notification::where('title', 'like', '%Towel%')->count());
        $this->assertSame('ok', $amenity->stockState(10));
    }
}
