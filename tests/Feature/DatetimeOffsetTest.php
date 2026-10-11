<?php

use App\Actions\CreateBooking;
use App\Models\Resource;
use Carbon\Carbon;

it('accepts explicit offsets and rejects a naive datetime on every datetime input', function (string $endpoint, string $field) {
    $headers = apiKeyHeader();
    $isPost = $endpoint === 'POST bookings';
    $startField = $isPost ? 'starts_at' : 'from';
    $endField = $isPost ? 'ends_at' : 'to';

    foreach (['Z', '-05:00', '+09:00', null] as $offset) {
        $resource = Resource::factory()->create(['timezone' => 'America/Chicago']);
        $start = Carbon::parse('2026-07-21T15:00:00Z');
        $end = Carbon::parse('2026-07-21T16:00:00Z');
        $input = [$startField => $start->toIso8601String(), $endField => $end->toIso8601String()];
        if ($offset !== null) {
            $input = [
                $startField => $offset === 'Z' ? '2026-07-21T15:00:00Z' : $start->copy()->setTimezone($offset)->toIso8601String(),
                $endField => $offset === 'Z' ? '2026-07-21T16:00:00Z' : $end->copy()->setTimezone($offset)->toIso8601String(),
            ];
        } else {
            $input[$field] = $field === $startField ? '2026-07-21T09:00:00' : '2026-07-21T21:00:00';
        }

        if (!$isPost) {
            new CreateBooking()->handle(
                resourceId: $resource->id, externalType: $resource->external_type,
                externalId: $resource->external_id, startsAt: $start, endsAt: $end,
            );
        }
        $path = "/api/resources/{$resource->id}/".($endpoint === 'GET busy' ? 'busy' : 'bookings');
        $response = $isPost
            ? $this->withHeaders($headers)->postJson($path, $input)
            : $this->withHeaders($headers)->getJson($path.'?'.http_build_query($input));

        if ($offset === null) {
            $response->assertUnprocessable()->assertJsonValidationErrors($field);
            expect(strtolower($response->json('message')))->toContain('offset')->toContain('required');
        } elseif ($endpoint === 'GET busy') {
            $response->assertOk()->assertExactJson([
                ['starts_at' => '2026-07-21T15:00:00+00:00', 'ends_at' => '2026-07-21T16:00:00+00:00'],
            ]);
        } else {
            $response->assertStatus($isPost ? 201 : 200)
                ->assertJsonPath($isPost ? 'starts_at' : '0.starts_at', '2026-07-21T15:00:00.000000Z')
                ->assertJsonPath($isPost ? 'ends_at' : '0.ends_at', '2026-07-21T16:00:00.000000Z');
        }
    }
})->with([
    ['POST bookings', 'starts_at'], ['POST bookings', 'ends_at'],
    ['GET busy', 'from'], ['GET busy', 'to'],
    ['GET bookings', 'from'], ['GET bookings', 'to'],
]);
