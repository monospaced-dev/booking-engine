<?php

namespace App\Http\Controllers;

use App\Actions\CreateBooking;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Resource;
use App\Rules\ExplicitDatetimeOffset;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    public function index(Request $request, Resource $resource)
    {
        $validated = $request->validate([
            'from' => ['required_with:to', 'nullable', 'date', new ExplicitDatetimeOffset],
            'to' => ['required_with:from', 'nullable', 'date', new ExplicitDatetimeOffset, 'after:from'],
        ]);

        if (! empty($validated['from']) && ! empty($validated['to'])) {
            $bookings = $resource->bookings()
                ->whereRaw('during && ?::tstzrange', [
                    sprintf(
                        '[%s,%s)',
                        Carbon::parse($validated['from'])->utc()->toIso8601String(),
                        Carbon::parse($validated['to'])->utc()->toIso8601String(),
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
            'starts_at' => ['required', 'date', new ExplicitDatetimeOffset],
            'ends_at' => ['required', 'date', new ExplicitDatetimeOffset, 'after:starts_at'],
            'metadata' => 'sometimes|array',
            'metadata.appointment_id' => 'sometimes|integer',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($request->headers->has('Idempotency-Key')) {
            Validator::make(['Idempotency-Key' => $idempotencyKey], [
                'Idempotency-Key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D'],
            ])->validate();
        }

        // Match the existing INSERT's UTC-second precision and validated metadata.
        $startsAt = Carbon::parse($validated['starts_at'])->utc()->startOfSecond();
        $endsAt = Carbon::parse($validated['ends_at'])->utc()->startOfSecond();
        $metadata = $validated['metadata'] ?? [];

        if ($idempotencyKey !== null) {
            $replay = $this->replay($resource, $idempotencyKey, $startsAt, $endsAt, $metadata);
            if ($replay !== null) {
                return $replay;
            }
        }

        try {
            $booking = $createBooking->handle(
                resourceId: $resource->id,
                externalType: $resource->external_type,
                externalId: $resource->external_id,
                startsAt: $startsAt,
                endsAt: $endsAt,
                metadata: $metadata,
                idempotencyKey: $idempotencyKey,
            );
        } catch (QueryException $e) {
            // SQLSTATE 23505 = unique_violation on (resource_id, idempotency_key) —
            // a concurrent request with the same key won the race.
            if ($e->getCode() === '23505' && $idempotencyKey !== null) {
                $replay = $this->replay($resource, $idempotencyKey, $startsAt, $endsAt, $metadata);
                if ($replay !== null) {
                    return $replay;
                }
            }
            throw $e;
        } catch (BookingConflictException $e) {
            return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage(), 'code' => 'booking_conflict'], 409);
        }

        return response()->json($booking, 201);
    }

    private function replay(Resource $resource, string $key, Carbon $startsAt, Carbon $endsAt, array $metadata): ?JsonResponse
    {
        $existing = $resource->bookings()->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }

        $parsed = $existing->parseDuring($existing->during);
        if (! $parsed['starts_at']->equalTo($startsAt) || ! $parsed['ends_at']->equalTo($endsAt)
            || $existing->metadata !== $metadata) {
            return response()->json([
                'message' => 'Idempotency key has already been used with a different booking payload.',
                'code' => 'idempotency_mismatch',
            ], 422);
        }

        $existing->starts_at = $parsed['starts_at'];
        $existing->ends_at = $parsed['ends_at'];

        return response()->json($existing, 200);
    }

    public function destroy(Resource $resource, Booking $booking)
    {
        if ($booking->resource_id !== $resource->id) {
            abort(404);
        }

        $booking->delete();

        return response()->noContent();
    }
}
