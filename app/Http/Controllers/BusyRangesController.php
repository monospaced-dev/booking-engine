<?php

namespace App\Http\Controllers;

use App\Actions\GetBusyRanges;
use App\Models\Resource;
use App\Rules\ExplicitDatetimeOffset;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BusyRangesController extends Controller
{
    public function index(Request $request, Resource $resource, GetBusyRanges $getBusyRanges)
    {
        $validated = $request->validate([
            'from' => ['required', 'date', new ExplicitDatetimeOffset()],
            'to' => ['required', 'date', new ExplicitDatetimeOffset(), 'after:from'],
        ]);

        $from = Carbon::parse($validated['from'])->utc();
        $to = Carbon::parse($validated['to'])->utc();

        $windows = $getBusyRanges->handle($resource, $from, $to);

        $formatted = collect($windows)->map(fn ($window) => [
            'starts_at' => $window['start']->utc()->toIso8601String(),
            'ends_at' => $window['end']->utc()->toIso8601String(),
        ]);

        return response()->json($formatted, 200);
    }
}
