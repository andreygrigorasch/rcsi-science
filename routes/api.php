<?php

use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']);
    Route::get('/rooms/{room}/bookings/today', [BookingController::class, 'today']);
});
