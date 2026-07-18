<?php

use App\Http\Controllers\BlackoutDateController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusyRangesController;
use App\Http\Controllers\ResourceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth.apikey')->group(function () {
    Route::post('/resources/{resource}/bookings', [BookingController::class, 'store']);
    Route::get('/resources/{resource}/bookings', [BookingController::class, 'index']);
    Route::delete('/resources/{resource}/bookings/{booking}', [BookingController::class, 'destroy']);

    Route::post('/resources', [ResourceController::class, 'store']);
    Route::put('resources/{resource}', [ResourceController::class, 'update']);
    Route::get('/resources/{resource}/busy', [BusyRangesController::class, 'index']);


    Route::post('/resources/{resource}/blackout-dates', [BlackoutDateController::class, 'store']);
    Route::delete('/resources/{resource}/blackout-dates/{blackoutDate}', [BlackoutDateController::class, 'destroy']);
});
