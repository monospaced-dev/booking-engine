<?php

namespace App\Http\Controllers;

use App\Actions\CreateBooking;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Resource;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function index(Request $request, Resource $resource)
    {
        $validated = $request->validate([
            'from' => 'required_with:to|nullable|date',
            'to' => 'required_with:from|nullable|date|after:from',
        ]);

        if (!empty($validated['from']) && !empty($validated['to'])) {
            $bookings = $resource->bookings()
                ->whereRaw('during && ?::tstzrange', [
                    sprintf(
                        '[%s,%s)',
                        Carbon::parse($validated['from'])->toIso8601String(),
                        Carbon::parse($validated['to'])->toIso8601String(),
                    ),
                ])
                ->get();
        } else {
            \Log::debug("BookingController: Fetching all bookings for resource {$resource->id}");
            $bookings = $resource->bookings()->get();
        }

        // Parse during to starts_at and ends_at fields in json
        foreach ($bookings as $booking) {
            $parsed = $booking->parseDuring($booking->during);
            $booking->starts_at = $parsed['starts_at'];
            $booking->ends_at = $parsed['ends_at'];
        }

        return response()->json(
            $bookings,
            200
        );
    }

    public function store(Request $request, Resource $resource, CreateBooking $createBooking)
    {
        $validated = $request->validate([
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'metadata' => 'sometimes|array',
            'metadata.appointment_id' => 'sometimes|integer',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey) {
            $existing = $resource->bookings()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                $parsed = $existing->parseDuring($existing->during);
                $existing->starts_at = $parsed['starts_at'];
                $existing->ends_at = $parsed['ends_at'];

                return response()->json($existing, 200);
            }
        }

        try {
            $booking = $createBooking->handle(
                resourceId: $resource->id,
                externalType: $resource->external_type,
                externalId: $resource->external_id,
                startsAt: Carbon::parse($validated['starts_at']),
                endsAt: Carbon::parse($validated['ends_at']),
                metadata: $validated['metadata'] ?? [],
                idempotencyKey: $idempotencyKey,
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // SQLSTATE 23505 = unique_violation on (resource_id, idempotency_key) —
            // a concurrent request with the same key won the race.
            if ($e->getCode() === '23505' && $idempotencyKey) {
                $existing = $resource->bookings()->where('idempotency_key', $idempotencyKey)->firstOrFail();

                $parsed = $existing->parseDuring($existing->during);
                $existing->starts_at = $parsed['starts_at'];
                $existing->ends_at = $parsed['ends_at'];

                return response()->json($existing, 200);
            }
            throw $e;
        } catch (BookingConflictException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json($booking, 201);
    }

    public function destroy(Resource $resource, Booking $booking)
    {
        if ($booking->resource_id !== $resource->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $booking->delete();

        return response()->noContent();
    }
}
