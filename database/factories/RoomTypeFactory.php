<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RoomTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Standard', 'Deluxe', 'Suite', 'Honeymoon', 'Presidential']),
            'rate' => fake()->randomFloat(2, 2000, 10000),
            'capacity' => fake()->numberBetween(1, 4),
            'status' => 'active',
        ];
    }
}
