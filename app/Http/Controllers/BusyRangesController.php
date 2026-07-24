<?php

namespace App\Http\Controllers;

use App\Actions\GetBusyRanges;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BusyRangesController extends Controller
{
    public function index(Request $request, Resource $resource, GetBusyRanges $getBusyRanges)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after:from',
        ]);

        $from = Carbon::parse($validated['from'], $resource->timezone);
        $to = Carbon::parse($validated['cto'], $resource->timezone);

        $windows = $getBusyRanges->handle($resource, $from, $to);

        $formatted = collect($windows)->map(fn ($window) => [
            'starts_at' => $window['start']->toIso8601String(),
            'ends_at' => $window['end']->toIso8601String(),
        ]);

        return response()->json($formatted, 200);
    }
}
