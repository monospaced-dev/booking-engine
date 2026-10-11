<?php

use App\Models\Resource;

it('returns every stored blackout field on POST matching GET', function (array $payload) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/blackout-dates";
    $created = $this->postJson($path, $payload)->assertCreated()->assertJsonStructure([
        'id', 'resource_id', 'date', 'start_time', 'end_time', 'note', 'created_at', 'updated_at',
    ]);
    $listed = $this->getJson($path)->assertOk();
    expect($created->json())->toEqual($listed->json('0'));
    expect($created->json('start_time'))->toBe(isset($payload['start_time']) ? $payload['start_time'].':00' : null);
    expect($created->json('end_time'))->toBe(isset($payload['end_time']) ? $payload['end_time'].':00' : null);
})->with([
    'full day' => [['date' => '2026-07-21']],
    'partial day' => [['date' => '2026-07-21', 'start_time' => '12:00', 'end_time' => '13:00', 'note' => 'Lunch']],
    'explicit nulls' => [['date' => '2026-07-21', 'start_time' => null, 'end_time' => null, 'note' => null]],
]);
