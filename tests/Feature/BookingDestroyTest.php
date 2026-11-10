<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_own_booking_and_reuse_the_slot(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
        Sanctum::actingAs($author);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->deleteJson("/api/bookings/{$booking->id}")->assertNotFound();

        $this->postJson('/api/bookings', [
            'room_id' => $booking->room_id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertCreated();
    }

    public function test_other_user_cannot_cancel_booking(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNotFound();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_invalid_ids_return_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/invalid')->assertNotFound();
        $this->deleteJson('/api/bookings/9223372036854775808')->assertNotFound();
    }
}
