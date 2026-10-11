<?php

use App\Http\Controllers\BlackoutController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BusyRangesController;
use App\Http\Controllers\ResourceController;
use Illuminate\Support\Facades\Route;

// Unauthenticated on purpose: k8s probes and external uptime monitors (Uptime
// Kuma) can't carry an API key, so this stays outside the auth.apikey group.
// Actually checks DB connectivity rather than returning a static 200, so a
// broken DB connection surfaces as unhealthy instead of a false-positive pass.
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        return response()->json(['status' => 'error', 'database' => 'unreachable', 'message' => 'Database unavailable.', 'code' => 'database_unavailable'], 503);
    }

    return response()->json(['status' => 'ok']);
});

Route::get('/ping', fn () => response()->noContent());

Route::pattern('resource', '[1-9][0-9]{0,17}');
Route::pattern('blackout', '[1-9][0-9]{0,17}');
Route::pattern('booking', '[1-9][0-9]{0,17}');

Route::middleware('auth.apikey')->group(function () {
    Route::post('/resources/{resource}/bookings', [BookingController::class, 'store']);
    Route::get('/resources/{resource}/bookings', [BookingController::class, 'index']);
    Route::get('/resources/{resource}/bookings/{booking}', [BookingController::class, 'show']);
    Route::patch('/resources/{resource}/bookings/{booking}', [BookingController::class, 'update']);
    Route::delete('/resources/{resource}/bookings/{booking}', [BookingController::class, 'destroy']);

    Route::post('/resources', [ResourceController::class, 'store']);
    Route::get('/resources/{resource}/busy', [BusyRangesController::class, 'index']);

    Route::get('/resources/{resource}/blackouts', [BlackoutController::class, 'index']);
    Route::post('/resources/{resource}/blackouts', [BlackoutController::class, 'store']);
    Route::delete('/resources/{resource}/blackouts/{blackout}', [BlackoutController::class, 'destroy']);

});
