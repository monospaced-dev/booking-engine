<?php

namespace App\Http\Controllers;

use App\Models\Resource;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'external_type' => 'required|string',
            'external_id' => 'required|integer',
        ]);

        // firstOrCreate re-selects the winning identity after a concurrent unique violation.
        $resource = Resource::firstOrCreate(['tenant_id' => $request->attributes->get('tenant_id')] + $validated);

        return response()->json([
            'id' => $resource->id,
            'external_type' => $resource->external_type,
            'external_id' => $resource->external_id,
            'created' => $resource->wasRecentlyCreated,
        ], $resource->wasRecentlyCreated ? 201 : 200);
    }
}
