<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['bail', 'required', 'integer', 'regex:/\A[1-9][0-9]*\z/'],
            'title' => ['bail', 'required', 'string', 'max:255', 'not_regex:/\x00/'],
            'starts_at' => ['bail', 'required', 'string'],
            'ends_at' => ['bail', 'required', 'string'],
        ], [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'ID комнаты должен быть положительным целым числом.',
            'room_id.regex' => 'ID комнаты должен быть положительным целым числом.',
            'title.required' => 'Укажите название брони.',
            'title.string' => 'Название брони должно быть строкой.',
            'title.max' => 'Название брони не должно превышать 255 символов.',
            'title.not_regex' => 'Название брони содержит недопустимый символ.',
            'starts_at.required' => 'Укажите начало брони.',
            'starts_at.string' => 'Начало брони должно быть строкой в формате ISO 8601.',
            'ends_at.required' => 'Укажите окончание брони.',
            'ends_at.string' => 'Окончание брони должно быть строкой в формате ISO 8601.',
        ]);

        $start = $this->parseTime($data['starts_at'], 'starts_at')->utc();
        $end = $this->parseTime($data['ends_at'], 'ends_at')->utc();

        $booking = DB::transaction(function () use ($request, $data, $start, $end): Booking {
            $room = Room::query()->lockForUpdate()->find($data['room_id']);

            if ($room === null) {
                throw ValidationException::withMessages(['room_id' => 'Комната не найдена.']);
            }

            $timezone = $room->office->timezone;
            $this->validateSlot($start->setTimezone($timezone), $end->setTimezone($timezone));

            $conflict = $room->bookings()
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages(['starts_at' => 'Комната уже занята в это время.']);
            }

            return $request->user()->bookings()->create([
                'room_id' => $room->id,
                'title' => $data['title'],
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $bookingId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($bookingId === false || $request->user()->bookings()->whereKey($bookingId)->delete() === 0, 404);

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }

    private function parseTime(string $value, string $field): CarbonImmutable
    {
        $format = <<<'REGEX'
            /\A
                (?<date>\d{4}(?<extended>-)?\d{2}(?(extended)-)\d{2}) T
                (?<time>(?:[01]\d|2[0-3])(?(extended):)[0-5]\d)
                (?:(?(extended):)(?<seconds>[0-5]\d)(?:[.,](?<fraction>\d+))?)?
                (?<offset>Z|[+-](?:[01]\d|2[0-3])(?::?[0-5]\d)?)
            \z/ix
            REGEX;

        $invalidDate = 'Укажите корректную дату и время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.';

        if (! preg_match($format, $value, $parts, PREG_UNMATCHED_AS_NULL)) {
            throw ValidationException::withMessages([$field => $invalidDate]);
        }

        $date = str_replace('-', '', $parts['date']);
        $hoursAndMinutes = str_replace(':', '', $parts['time']);
        $timestamp = $date.'T'.$hoursAndMinutes.($parts['seconds'] ?? '00').$parts['offset'];
        $time = DateTimeImmutable::createFromFormat('!Ymd\THisP', $timestamp);

        if ($time === false || DateTimeImmutable::getLastErrors() !== false || $time->format('Y') === '0000') {
            throw ValidationException::withMessages([$field => $invalidDate]);
        }

        if (trim($parts['fraction'] ?? '', '0') !== '') {
            throw ValidationException::withMessages([$field => 'Время брони должно быть указано без долей секунды.']);
        }

        return CarbonImmutable::instance($time);
    }

    private function validateSlot(CarbonImmutable $start, CarbonImmutable $end): void
    {
        foreach (['starts_at' => $start, 'ends_at' => $end] as $field => $time) {
            if ($time->minute % 15 !== 0 || $time->second !== 0) {
                throw ValidationException::withMessages([$field => 'Выберите время с шагом 15 минут по местному времени офиса.']);
            }
        }

        if ($start->lt($start->setTime(9, 0))) {
            throw ValidationException::withMessages(['starts_at' => 'Бронь должна начинаться не раньше 09:00 по местному времени офиса.']);
        }

        if (! $start->isSameDay($end) || $end->gt($start->setTime(20, 0))) {
            throw ValidationException::withMessages(['ends_at' => 'Бронь должна завершиться до 20:00 включительно в тот же день по местному времени офиса.']);
        }

        $duration = $end->getTimestamp() - $start->getTimestamp();

        if ($duration < 15 * 60 || $duration > 4 * 60 * 60 || $duration % (15 * 60) !== 0) {
            throw ValidationException::withMessages(['ends_at' => 'Длительность брони должна быть от 15 минут до 4 часов с шагом 15 минут.']);
        }
    }
}
