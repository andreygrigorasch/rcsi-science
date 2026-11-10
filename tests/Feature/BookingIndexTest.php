<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_own_bookings(): void
    {
        $user = User::factory()->create();
        $own = Booking::factory()->for($user)->create();
        Booking::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/bookings')->assertUnauthorized();
    }
}
