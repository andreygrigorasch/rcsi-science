<?php

namespace Database\Factories;

use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Office>
 */
class OfficeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Москва',
            'timezone' => 'Europe/Moscow',
        ];
    }

    public function novosibirsk(): static
    {
        return $this->state(fn () => [
            'name' => 'Новосибирск',
            'timezone' => 'Asia/Novosibirsk',
        ]);
    }
}
