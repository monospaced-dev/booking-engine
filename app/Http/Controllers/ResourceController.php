<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'external_type' => 'required|string',
            'external_id' => 'required|integer',
            'timezone' => 'required|string|timezone',
        ]);

        $resource = Resource::firstOrCreate(
            [
            'external_type' => $validated['external_type'],
            'external_id' => $validated['external_id']
            ],
            [
                'timezone' => $validated['timezone']
            ]
        );

        return response()->json([
            'id' => $resource->id,
            'external_type' => $resource->external_type,
            'external_id' => $resource->external_id,
            'timezone' => $resource->timezone,
            'created' => $resource->wasRecentlyCreated
        ]);
    }

    /**
     * Update the specified resource's timezone
     */
    public function update(Request $request, Resource $resource)
    {
        $validated = $request->validate([
            'timezone' => 'required|string|timezone',
        ]);

        $resource->update(['timezone' => $validated['timezone']]);

        return response()->json([
            'id' => $resource->id,
            'external_type' => $resource->external_type,
            'external_id' => $resource->external_id,
            'timezone' => $resource->timezone
        ]);
    }
}
