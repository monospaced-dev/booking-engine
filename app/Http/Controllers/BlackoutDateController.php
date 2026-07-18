<?php

namespace App\Http\Controllers;

use App\Models\BlackoutDate;
use App\Models\Resource;
use Illuminate\Http\Request;

class BlackoutDateController extends Controller
{
    /*
     * Blackout dates controller for the API.
     *
     * @param Request $request
     * @param Resource $resource
     *
     * @return void
     */
    public function store(Request $request, Resource $resource)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i|required_with:end_time',
            'end_time' => 'nullable|date_format:H:i|required_with:start_time|after:start_time',
            'reason' => 'nullable|string',
        ]);

        $blackoutDate = $resource->blackoutDates()->create($validated);

        return response()->json($blackoutDate, 201);
    }

    /*
     * Delete blackout date.
     *
     * @param Resource $resource
     * @param int $id
     *
     * @return void
     */
    public function destroy(Resource $resource, BlackoutDate $blackoutDate)
    {
        if ($blackoutDate->resource_id !== $resource->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $blackoutDate->delete();

        return response()->noContent();
    }
}
