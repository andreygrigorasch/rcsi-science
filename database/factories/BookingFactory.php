<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = now()->addDay()->setTime(10, 0);

        return [
            'room_id' => Room::factory(),
            'user_id' => User::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'title' => fake()->sentence(3),
        ];
    }
}
