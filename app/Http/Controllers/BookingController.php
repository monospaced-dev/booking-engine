<?php

namespace App\Http\Controllers;

use App\Actions\CreateBooking;
use App\Actions\RescheduleBooking;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Resource;
use App\Rules\ExplicitDatetimeOffset;
use App\Rules\FlatMetadata;
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
                ->overlapping(Carbon::parse($validated['from'])->utc(), Carbon::parse($validated['to'])->utc())
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
        // Metadata is opaque: undo global string trimming/null conversion only
        // for this field, keeping the original JSON types and values.
        $json = json_decode($request->getContent());
        if (is_object($json) && property_exists($json, 'metadata')) {
            $request->merge(['metadata' => json_decode($request->getContent(), true)['metadata']]);
        }

        $validated = $request->validate([
            'starts_at' => ['required', 'date', new ExplicitDatetimeOffset],
            'ends_at' => ['required', 'date', new ExplicitDatetimeOffset, 'after:starts_at'],
            'metadata' => ['sometimes', 'array', new FlatMetadata(is_object($json->metadata ?? null))],
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
            // Postgres can check the exclusion constraint before the unique key.
            // The failed INSERT transaction has rolled back; re-read the winner.
            if ($idempotencyKey !== null) {
                $replay = $this->replay($resource, $idempotencyKey, $startsAt, $endsAt, $metadata);
                if ($replay !== null) {
                    return $replay;
                }
            }

            return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage(), 'code' => 'booking_conflict'], 409);
        }

        return $this->bookingResponse($booking, 201);
    }

    private function replay(Resource $resource, string $key, Carbon $startsAt, Carbon $endsAt, array $metadata): ?JsonResponse
    {
        $existing = $resource->bookings()->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }

        $storedMetadata = $existing->metadata ?? [];
        ksort($storedMetadata);
        ksort($metadata);
        $parsed = $existing->parseDuring($existing->during);
        if (! $parsed['starts_at']->equalTo($startsAt) || ! $parsed['ends_at']->equalTo($endsAt)
            || $storedMetadata !== $metadata) {
            return response()->json([
                'message' => 'Idempotency key has already been used with a different booking payload.',
                'code' => 'idempotency_mismatch',
            ], 422);
        }

        $existing->starts_at = $parsed['starts_at'];
        $existing->ends_at = $parsed['ends_at'];

        return $this->bookingResponse($existing);
    }

    private function bookingResponse(Booking $booking, int $status = 200): JsonResponse
    {
        $parsed = $booking->parseDuring($booking->during);
        $booking->starts_at = $parsed['starts_at'];
        $booking->ends_at = $parsed['ends_at'];

        return response()->json($booking, $status)->header('ETag', '"'.$booking->lock_version.'"');
    }

    public function show(Resource $resource, Booking $booking): JsonResponse
    {
        abort_if($booking->resource_id !== $resource->id, 404);

        return $this->bookingResponse($booking);
    }

    public function update(Request $request, Resource $resource, Booking $booking, RescheduleBooking $rescheduleBooking): JsonResponse
    {
        abort_if($booking->resource_id !== $resource->id, 404);
        if (! $request->headers->has('If-Match')) {
            return response()->json(['message' => 'If-Match is required.', 'code' => 'precondition_required'], 428);
        }

        $validated = $request->validate([
            'starts_at' => ['required', 'date', new ExplicitDatetimeOffset],
            'ends_at' => ['required', 'date', new ExplicitDatetimeOffset, 'after:starts_at'],
        ]);

        // Accept exactly one strong, quoted version. Invalid/weak/wildcard tags
        // cannot match. Bound the integer before passing it to Postgres.
        $tag = $request->header('If-Match');
        if (! preg_match('/^"([1-9][0-9]{0,9})"$/D', $tag, $matches) || (int) $matches[1] > 2147483647) {
            return $this->preconditionFailed();
        }

        try {
            $updated = $rescheduleBooking->handle($resource->id, $booking->id, $matches[1],
                Carbon::parse($validated['starts_at'])->utc()->startOfSecond(),
                Carbon::parse($validated['ends_at'])->utc()->startOfSecond());
        } catch (BookingConflictException $e) {
            return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage(), 'code' => 'booking_conflict'], 409);
        }

        if ($updated === null) {
            // Also handles deletion after route binding without turning it into
            // a stale-version response for a booking that no longer exists.
            abort_unless($resource->bookings()->whereKey($booking->id)->exists(), 404);

            return $this->preconditionFailed();
        }

        return $this->bookingResponse($updated);
    }

    private function preconditionFailed(): JsonResponse
    {
        return response()->json(['message' => 'Booking version does not match If-Match.', 'code' => 'precondition_failed'], 412);
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
