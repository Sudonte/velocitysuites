<?php

namespace Database\Factories;

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
        $types = ['Standard', 'Deluxe', 'Suite', 'Honeymoon', 'Presidential'];

        return [
            'room_number' => fake()->unique()->numerify('###'),
            'room_name' => fake()->word() . ' ' . fake()->word(),
            'room_type' => fake()->randomElement($types),
            'room_rate' => fake()->randomFloat(2, 2000, 10000),
            'room_capacity' => fake()->numberBetween(1, 4),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(['available', 'occupied', 'reserved']),
        ];
    }
}
