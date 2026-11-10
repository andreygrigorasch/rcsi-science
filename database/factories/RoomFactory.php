<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'name' => fake()->unique()->word(),
            'capacity' => fake()->numberBetween(4, 20),
        ];
    }
}
