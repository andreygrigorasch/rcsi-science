<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $conflict = DB::select("SELECT id FROM bookings
            WHERE room_id = {$request->room_id}
            AND starts_at <= '{$request->ends_at}'
            AND ends_at >= '{$request->starts_at}'");

        if ($conflict) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        $start = Carbon::parse($request->starts_at);
        if ($start->hour < 9 || $start->hour >= 20) {
            return response()->json(['error' => 'Нерабочее время'], 422);
        }

        $booking = Booking::create($request->all());

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return $booking;
    }

    public function destroy($id)
    {
        Booking::findOrFail($id)->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
