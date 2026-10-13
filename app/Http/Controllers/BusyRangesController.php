<?php

namespace App\Http\Controllers;

use App\Actions\GetBusyRanges;
use App\Models\Resource;
use App\Rules\ExplicitDatetimeOffset;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BusyRangesController extends Controller
{
    public function index(Request $request, Resource $resource, GetBusyRanges $getBusyRanges)
    {
        $validated = $request->validate([
            'from' => ['required', 'date', new ExplicitDatetimeOffset],
            'to' => ['required', 'date', new ExplicitDatetimeOffset, 'after:from'],
        ]);

        $from = Carbon::parse($validated['from'])->utc();
        $to = Carbon::parse($validated['to'])->utc();

        $windows = $getBusyRanges->handle($resource, $from, $to);

        return response()->json($this->format($windows), 200);
    }

    public function batch(Request $request, GetBusyRanges $getBusyRanges)
    {
        $validated = $request->validate([
            'resource_ids' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'resource_ids.*' => ['required', function ($attribute, $value, $fail) {
                if (! is_int($value)) {
                    $fail('Each resource ID must be an integer.');
                }
            }],
            'from' => ['required', 'date', new ExplicitDatetimeOffset],
            'to' => ['required', 'date', new ExplicitDatetimeOffset, 'after:from'],
        ]);

        $from = Carbon::parse($validated['from'])->utc();
        $to = Carbon::parse($validated['to'])->utc();
        if ($from->diffInSeconds($to, false) > 366 * 86400) {
            throw ValidationException::withMessages(['to' => 'The busy window may not be longer than 366 days.']);
        }

        $ids = array_values(array_unique($validated['resource_ids']));
        $existing = Resource::forTenant($request->attributes->get('tenant_id'))->whereIn('id', $ids)->pluck('id')->all();
        $missing = array_values(array_diff($ids, $existing));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'resource_ids' => 'Unknown resource IDs: '.implode(', ', $missing).'.',
            ]);
        }

        $busy = [];
        foreach ($getBusyRanges->handleBatch($ids, $from, $to, $request->attributes->get('tenant_id')) as $id => $windows) {
            $busy[$id] = $this->format($windows);
        }

        return response()->json(['busy' => (object) $busy]);
    }

    private function format(array $windows): array
    {
        return array_map(fn ($window) => [
            'starts_at' => $window['start']->utc()->toIso8601String(),
            'ends_at' => $window['end']->utc()->toIso8601String(),
        ], $windows);
    }
}
