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
        return [
            'room_number' => fake()->unique()->numerify('###'),
            'room_name' => fake()->word() . ' ' . fake()->word(),
            'room_type_id' => RoomType::inRandomOrder()->first()?->id
                ?? RoomType::factory()->create()->id,
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(['available', 'occupied', 'reserved']),
        ];
    }
}
