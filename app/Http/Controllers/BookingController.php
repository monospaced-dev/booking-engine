<?php

namespace App\Http\Controllers;

use App\Actions\CreateBooking;
use App\Actions\RescheduleBooking;
use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\Resource;
use App\Rules\BookingDuration;
use App\Rules\ExplicitDatetimeOffset;
use App\Rules\FlatMetadata;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $bookings = $resource->bookings()->active()
                ->overlapping(Carbon::parse($validated['from'])->utc(), Carbon::parse($validated['to'])->utc())
                ->orderByRaw('lower(during)')->orderBy('id')->get();
        } else {
            \Log::debug("BookingController: Fetching all bookings for resource {$resource->id}");
            $bookings = $resource->bookings()->active()->orderByRaw('lower(during)')->orderBy('id')->get();
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
            'ends_at' => ['required', 'date', new ExplicitDatetimeOffset, 'after:starts_at', new BookingDuration($request->input('starts_at'))],
            'hold_seconds' => ['sometimes', 'required', 'integer', 'between:60,900'],
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
        $holdSeconds = isset($validated['hold_seconds']) ? (int) $validated['hold_seconds'] : null;

        if ($idempotencyKey !== null) {
            $replay = $this->replay($resource, $idempotencyKey, $startsAt, $endsAt, $metadata, $holdSeconds);
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
                holdSeconds: $holdSeconds,
            );
        } catch (QueryException $e) {
            // SQLSTATE 23505 = unique_violation on (resource_id, idempotency_key) —
            // a concurrent request with the same key won the race.
            if ($e->getCode() === '23505' && $idempotencyKey !== null) {
                $replay = $this->replay($resource, $idempotencyKey, $startsAt, $endsAt, $metadata, $holdSeconds);
                if ($replay !== null) {
                    return $replay;
                }
            }
            throw $e;
        } catch (BookingConflictException $e) {
            // Postgres can check the exclusion constraint before the unique key.
            // The failed INSERT transaction has rolled back; re-read the winner.
            if ($idempotencyKey !== null) {
                $replay = $this->replay($resource, $idempotencyKey, $startsAt, $endsAt, $metadata, $holdSeconds);
                if ($replay !== null) {
                    return $replay;
                }
            }

            return response()->json(['error' => $e->getMessage(), 'message' => $e->getMessage(), 'code' => 'booking_conflict'], 409);
        }

        return $this->bookingResponse($booking, 201);
    }

    private function replay(Resource $resource, string $key, Carbon $startsAt, Carbon $endsAt, array $metadata, ?int $holdSeconds): ?JsonResponse
    {
        $existing = $resource->bookings()->active()->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }

        $storedMetadata = $existing->metadata ?? [];
        ksort($storedMetadata);
        ksort($metadata);
        $parsed = $existing->parseDuring($existing->during);
        if (! $parsed['starts_at']->equalTo($startsAt) || ! $parsed['ends_at']->equalTo($endsAt)
            || $storedMetadata !== $metadata || $existing->hold_seconds !== $holdSeconds) {
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
            'ends_at' => ['required', 'date', new ExplicitDatetimeOffset, 'after:starts_at', new BookingDuration($request->input('starts_at'))],
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

    public function confirm(Request $request, Resource $resource, string $booking): JsonResponse
    {
        $version = null;
        if ($request->headers->has('If-Match')) {
            $tag = $request->header('If-Match');
            if (! preg_match('/^"([1-9][0-9]{0,9})"$/D', $tag, $matches) || (int) $matches[1] > 2147483647) {
                // Resolve ownership before reporting a precondition failure.
                abort_unless($resource->bookings()->whereKey($booking)->exists(), 404);

                return $this->preconditionFailed();
            }
            $version = $matches[1];
        }

        return DB::transaction(function () use ($resource, $booking, $version) {
            $row = DB::selectOne("UPDATE bookings SET status = 'confirmed', expires_at = NULL,
                lock_version = lock_version + 1, updated_at = clock_timestamp()
                WHERE id = ? AND resource_id = ? AND status = 'held' AND expires_at > now()"
                .($version === null ? '' : ' AND lock_version = ?').' RETURNING *',
                $version === null ? [$booking, $resource->id] : [$booking, $resource->id, $version]);
            if ($row !== null) {
                return $this->bookingResponse((new Booking)->newFromBuilder((array) $row));
            }
            // Lock fallback reads so concurrent deletes/updates cannot make an
            // already-confirmed success return a stale object or ETag.
            $existing = $resource->bookings()->whereKey($booking)->lockForUpdate()->first();
            if ($existing === null) {
                $expired = DB::table('expired_booking_holds')->where('booking_id', $booking)->where('resource_id', $resource->id)->exists();
                abort_unless($expired, 404);

                return $this->holdExpired();
            }
            if ($version !== null && (string) $existing->lock_version !== $version) {
                return $this->preconditionFailed();
            }
            if ($existing->status === 'confirmed') {
                return $this->bookingResponse($existing);
            }

            return $this->holdExpired();
        });
    }

    private function holdExpired(): JsonResponse
    {
        return response()->json(['message' => 'Booking hold has expired.', 'code' => 'hold_expired'], 410);
    }

    private function preconditionFailed(): JsonResponse
    {
        return response()->json(['message' => 'Booking version does not match If-Match.', 'code' => 'precondition_failed'], 412);
    }

    public function destroy(Request $request, Resource $resource, Booking $booking)
    {
        if ($booking->resource_id !== $resource->id) {
            abort(404);
        }

        $query = $resource->bookings()->whereKey($booking->id);
        if ($request->headers->has('If-Match')) {
            $tag = $request->header('If-Match');
            if (! preg_match('/^"([1-9][0-9]{0,9})"$/D', $tag, $matches) || (int) $matches[1] > 2147483647) {
                return $this->preconditionFailed();
            }
            // PostgreSQL rechecks this predicate after a concurrent UPDATE commits.
            // Never compare against the potentially stale route-bound model version.
            if ($query->where('lock_version', $matches[1])->delete() === 0) {
                return $this->preconditionFailed();
            }
        } else {
            $query->delete();
        }

        return response()->noContent();
    }
}
