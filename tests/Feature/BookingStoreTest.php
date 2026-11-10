<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', $this->payload($room))->assertUnauthorized();

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_uses_authenticated_author_and_stores_utc(): void
    {
        $room = Room::factory()->for(Office::factory()->novosibirsk())->create();
        $author = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($author);

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'user_id' => $other->id,
            'starts_at' => '2026-11-02T05:30:00+03:30',
            'ends_at' => '2026-11-02T02:15Z',
        ]))
            ->assertCreated()
            ->assertJsonPath('user_id', $author->id)
            ->assertJsonPath('starts_at', '2026-11-02T02:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T02:15:00.000000Z');

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'room_id' => $room->id,
            'user_id' => $author->id,
            'starts_at' => '2026-11-02 02:00:00',
            'ends_at' => '2026-11-02 02:15:00',
        ]);
    }

    public function test_accepts_compact_iso_8601_times(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '20261102T100000+0300',
            'ends_at' => '20261102T1100+0300',
        ]))
            ->assertCreated()
            ->assertJsonPath('starts_at', '2026-11-02T07:00:00.000000Z')
            ->assertJsonPath('ends_at', '2026-11-02T08:00:00.000000Z');
    }

    public function test_accepts_boundary_times_and_does_not_restrict_calendar_dates(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 11, 10));

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2024-06-08T09:00:00+03',
            'ends_at' => '2024-06-08T09:15:00+0300',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2040-01-01T16:00:00+03:00',
            'ends_at' => '2040-01-01T20:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_applies_quarter_hour_grid_in_office_timezone(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T06:53:00.0000000-00:07',
            'ends_at' => '2026-11-02T07:08:00-00:07',
        ]))->assertCreated();
    }

    public function test_allows_adjacent_slots_and_same_slot_in_another_room(): void
    {
        $room = Room::factory()->create();
        $otherRoom = Room::factory()->for($room->office)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($room))->assertCreated();

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->payload($otherRoom))->assertCreated();

        $this->assertDatabaseCount('bookings', 4);
    }

    #[DataProvider('overlappingSlots')]
    public function test_rejects_overlaps_even_for_another_user(string $start, string $end): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/bookings', $this->payload($room))->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/bookings', $this->payload($room, ['starts_at' => $start, 'ends_at' => $end]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at')
            ->assertJsonPath('errors.starts_at.0', 'Комната уже занята в это время.');

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlappingSlots(): array
    {
        return [
            'identical instants in UTC' => ['2026-11-02T07:00:00Z', '2026-11-02T08:00:00Z'],
            'overlaps left edge' => ['2026-11-02T09:30:00+03:00', '2026-11-02T10:30:00+03:00'],
            'overlaps right edge' => ['2026-11-02T10:30:00+03:00', '2026-11-02T11:30:00+03:00'],
            'inside existing slot' => ['2026-11-02T10:15:00+03:00', '2026-11-02T10:45:00+03:00'],
            'contains existing slot' => ['2026-11-02T09:30:00+03:00', '2026-11-02T11:30:00+03:00'],
        ];
    }

    #[DataProvider('invalidSlots')]
    public function test_rejects_invalid_slots(string $start, string $end, string $field): void
    {
        $room = Room::factory()->for(Office::factory()->novosibirsk())->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($room, ['starts_at' => $start, 'ends_at' => $end]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidSlots(): array
    {
        return [
            'before opening' => ['2026-11-02T08:45:00+07:00', '2026-11-02T09:15:00+07:00', 'starts_at'],
            'after closing only in office time' => ['2026-11-02T15:45:00+03:00', '2026-11-02T16:15:00+03:00', 'ends_at'],
            'crosses local midnight' => ['2026-11-02T19:45:00+07:00', '2026-11-03T09:00:00+07:00', 'ends_at'],
            'longer than four hours' => ['2026-11-02T10:00:00+07:00', '2026-11-02T14:15:00+07:00', 'ends_at'],
            'zero duration' => ['2026-11-02T10:00:00+07:00', '2026-11-02T10:00:00+07:00', 'ends_at'],
            'end before start' => ['2026-11-02T10:00:00+07:00', '2026-11-02T09:45:00+07:00', 'ends_at'],
            'shorter than fifteen minutes' => ['2026-11-02T10:00:00+07:00', '2026-11-02T10:10:00+07:00', 'ends_at'],
            'off-grid start' => ['2026-11-02T10:10:00+07:00', '2026-11-02T11:00:00+07:00', 'starts_at'],
            'off-grid end' => ['2026-11-02T10:00:00+07:00', '2026-11-02T11:05:00+07:00', 'ends_at'],
            'nonzero seconds' => ['2026-11-02T10:00:01+07:00', '2026-11-02T11:00:00+07:00', 'starts_at'],
            'sub-microsecond precision' => ['2026-11-02T10:00:00+07:00', '2026-11-02T11:00:00.00000001+07:00', 'ends_at'],
            'impossible calendar date' => ['2026-02-30T10:00:00+07:00', '2026-03-02T11:00:00+07:00', 'starts_at'],
            'impossible compact calendar date' => ['20260230T100000+0700', '20260302T110000+0700', 'starts_at'],
            'offset missing' => ['2026-11-02T10:00:00', '2026-11-02T11:00:00+07:00', 'starts_at'],
            'invalid offset' => ['2026-11-02T10:00:00+07:99', '2026-11-02T11:00:00+07:00', 'starts_at'],
            'relative input' => ['tomorrow', '2026-11-02T11:00:00+07:00', 'starts_at'],
        ];
    }

    public function test_returns_field_errors_for_missing_values(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);
    }

    public function test_returns_field_errors_for_malformed_input_before_querying_bookings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            'room_id' => '1 OR 1=1',
            'title' => str_repeat('а', 256),
            'starts_at' => [],
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['room_id', 'title', 'starts_at']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_rejects_unknown_room(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->payload($room, ['room_id' => $room->id + 1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id')
            ->assertJsonPath('errors.room_id.0', 'Комната не найдена.');

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Room $room, array $overrides = []): array
    {
        return array_replace([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], $overrides);
    }
}
