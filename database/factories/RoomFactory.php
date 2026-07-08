<?php

namespace Database\Factories;

use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $roomType = RoomType::inRandomOrder()->first() ?? RoomType::factory()->create();

        return [
            'room_number' => fake()->unique()->numerify('###'),
            'room_name' => fake()->word() . ' ' . fake()->word(),
            'room_type_id' => $roomType->id,
            'room_capacity' => $roomType->capacity,
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(['available', 'occupied', 'reserved']),
        ];
    }
}
