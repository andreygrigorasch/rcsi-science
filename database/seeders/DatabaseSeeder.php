<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $moscow = Office::create(['name' => 'Москва', 'timezone' => 'Europe/Moscow']);
        $moscow->rooms()->createMany([
            ['name' => 'Арбат', 'capacity' => 6],
            ['name' => 'Тверская', 'capacity' => 12],
            ['name' => 'Остоженка', 'capacity' => 4],
        ]);

        $novosibirsk = Office::create(['name' => 'Новосибирск', 'timezone' => 'Asia/Novosibirsk']);
        $novosibirsk->rooms()->createMany([
            ['name' => 'Обь', 'capacity' => 8],
            ['name' => 'Академгородок', 'capacity' => 10],
        ]);

        $this->createUserWithToken('Анна', 'anna@example.com', 'anna-token');
        $this->createUserWithToken('Борис', 'boris@example.com', 'boris-token');
    }

    /**
     * Постоянный токен только для демонстрации: в базе хранится его хеш, как у обычного токена Sanctum.
     */
    private function createUserWithToken(string $name, string $email, string $token): void
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);

        $user->tokens()->create([
            'name' => 'demo',
            'token' => hash('sha256', $token),
            'abilities' => ['*'],
        ]);
    }
}
