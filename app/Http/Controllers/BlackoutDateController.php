<?php

namespace App\Http\Controllers;

use App\Models\BlackoutDate;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BlackoutDateController extends Controller
{

    /**
     * List blackout dates.
     *
     * @param Request $request
     * @param Resource $resource
     *
     * @return JsonResponse
     */
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $blackoutDates = $resource->blackoutDates()->orderBy('date')->get();

        return response()->json($blackoutDates);
    }

    /**
     * Blackout dates controller for the API.
     *
     * @param Request $request
     * @param Resource $resource
     *
     * @return JsonResponse
     */
    public function store(Request $request, Resource $resource): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i|required_with:end_time',
            'end_time' => 'nullable|date_format:H:i|required_with:start_time|after:start_time',
            'note' => 'nullable|string',
        ]);

        $blackoutDate = $resource->blackoutDates()->create($validated);

        return response()->json($blackoutDate, 201);
    }

    /**
     * Delete blackout date.
     *
     * @param Resource $resource
     * @param BlackoutDate $blackoutDate
     *
     * @return JsonResponse|Response
     */
    public function destroy(Resource $resource, BlackoutDate $blackoutDate): JsonResponse|Response
    {
        if ($blackoutDate->resource_id !== $resource->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $blackoutDate->delete();

        return response()->noContent();
    }
}
