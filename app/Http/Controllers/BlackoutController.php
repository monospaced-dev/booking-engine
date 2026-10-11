<?php

namespace App\Http\Controllers;

use App\Helpers\BookingRange;
use App\Models\Blackout;
use App\Models\Resource;
use App\Rules\BookingDuration;
use App\Rules\ExplicitDatetimeOffset;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BlackoutController extends Controller
{
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required_with:to', 'nullable', 'date', new ExplicitDatetimeOffset],
            'to' => ['required_with:from', 'nullable', 'date', new ExplicitDatetimeOffset, 'after:from'],
        ]);
        $query = $resource->blackouts();
        if (! empty($validated['from']) && ! empty($validated['to'])) {
            $query->overlapping(Carbon::parse($validated['from'])->utc(), Carbon::parse($validated['to'])->utc());
        }

        return response()->json($query->get());
    }

    public function store(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date', new ExplicitDatetimeOffset],
            'ends_at' => ['required', 'date', new ExplicitDatetimeOffset, 'after:starts_at', new BookingDuration($request->input('starts_at'))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $blackout = $resource->blackouts()->create([
            'during' => BookingRange::literal(Carbon::parse($validated['starts_at'])->utc(), Carbon::parse($validated['ends_at'])->utc()),
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json($blackout->refresh(), 201);
    }

    public function destroy(Resource $resource, Blackout $blackout): Response
    {
        if ($blackout->resource_id !== $resource->id) {
            abort(404);
        }
        $blackout->delete();

        return response()->noContent();
    }
}
