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
        $from = $request->query('from');
        $to = $request->query('to');

        if ($from && $to) {
            $bookings = $resource->bookings()
                ->whereRaw('during && ?::tstzrange', [sprintf('[%s,%s)', $from, $to)])
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
        ]);

        try {
            $booking = $createBooking->handle(
                resourceId: $resource->id,
                externalType: $resource->external_type,
                externalId: $resource->external_id,
                startsAt: Carbon::parse($validated['starts_at']),
                endsAt: Carbon::parse($validated['ends_at']),
                metadata: $validated['metadata'] ?? [],
            );
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
