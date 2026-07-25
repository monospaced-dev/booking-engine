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

// Unauthenticated on purpose: k8s probes and external uptime monitors (Uptime
// Kuma) can't carry an API key, so this stays outside the auth.apikey group.
// Actually checks DB connectivity rather than returning a static 200, so a
// broken DB connection surfaces as unhealthy instead of a false-positive pass.
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        return response()->json(['status' => 'error', 'database' => 'unreachable'], 503);
    }

    return response()->json(['status' => 'ok']);
});

Route::middleware('auth.apikey')->group(function () {
    Route::post('/resources/{resource}/bookings', [BookingController::class, 'store']);
    Route::get('/resources/{resource}/bookings', [BookingController::class, 'index']);
    Route::delete('/resources/{resource}/bookings/{booking}', [BookingController::class, 'destroy']);

    Route::post('/resources', [ResourceController::class, 'store']);
    Route::put('resources/{resource}', [ResourceController::class, 'update']);
    Route::get('/resources/{resource}/busy', [BusyRangesController::class, 'index']);


    Route::post('/resources/{resource}/blackout-dates', [BlackoutDateController::class, 'store']);
    Route::get('/resources/{resource}/blackout-dates', [BlackoutDateController::class, 'index']);
    Route::delete('/resources/{resource}/blackout-dates/{blackoutDate}', [BlackoutDateController::class, 'destroy']);
});
