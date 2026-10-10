<?php

namespace Tests\Unit;

use App\Models\RoomType;
use App\Support\GuestCapacity;
use PHPUnit\Framework\TestCase;

class GuestCapacityTest extends TestCase
{
    private function type(int $max, ?int $min = null): RoomType
    {
        $t = new RoomType();
        $t->capacity = $max;
        $t->min_capacity = $min;

        return $t;
    }

    public function test_max_is_capacity_times_quantity_across_lines(): void
    {
        $lines = [
            ['room_type' => $this->type(2), 'quantity' => 2],
            ['room_type' => $this->type(4), 'quantity' => 1],
        ];

        $this->assertSame(['min' => 1, 'max' => 8], GuestCapacity::range($lines));
        $this->assertNull(GuestCapacity::error($lines, 8));
        $this->assertStringContainsString('exceed the total capacity (8)', GuestCapacity::error($lines, 9));
    }

    public function test_default_minimum_lets_one_guest_book_several_rooms(): void
    {
        $lines = [['room_type' => $this->type(2, 1), 'quantity' => 3]];

        $this->assertNull(GuestCapacity::error($lines, 1));
    }

    public function test_raised_minimum_is_enforced_per_room(): void
    {
        $lines = [
            ['room_type' => $this->type(6, 3), 'quantity' => 2],
            ['room_type' => $this->type(2, 1), 'quantity' => 1],
        ];

        $this->assertSame(['min' => 6, 'max' => 14], GuestCapacity::range($lines));
        $this->assertStringContainsString('at least 6 guest(s)', GuestCapacity::error($lines, 5));
        $this->assertNull(GuestCapacity::error($lines, 6));
    }

    public function test_missing_min_capacity_column_behaves_as_one(): void
    {
        $this->assertSame(1, GuestCapacity::minOf($this->type(2)));
    }
}
