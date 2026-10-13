<?php

use App\Actions\CreateBooking;
use App\Models\Blackout;
use App\Models\Resource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function batchWindow(): array
{
    return ['from' => '2026-07-21T10:00:00Z', 'to' => '2026-07-21T18:00:00Z'];
}

it('matches single busy responses across resources with overlaps adjacency and clipping', function () {
    $resources = Resource::factory()->count(3)->create();
    foreach ($resources as $index => $resource) {
        foreach ([['09:00', '11:00'], ['12:00', '13:00'], ['17:00', '19:00']] as [$start, $end]) {
            (new CreateBooking)->handle($resource->id, $resource->external_type, $resource->external_id,
                Carbon::parse("2026-07-21T{$start}:00Z"), Carbon::parse("2026-07-21T{$end}:00Z"));
        }
        foreach ([['08:00', '10:30'], ['10:30', '12:00'], ['13:00', '14:00'], ['16:30', '20:00']] as [$start, $end]) {
            Blackout::factory()->create(['resource_id' => $resource->id, 'during' => "[2026-07-21T{$start}:00Z,2026-07-21T{$end}:00Z)"]);
        }
        if ($index === 1) {
            Blackout::factory()->create(['resource_id' => $resource->id, 'during' => '[2026-07-21T14:00:00Z,2026-07-21T16:30:00Z)']);
        }
    }
    // Unrequested data must never leak into the result.
    Blackout::factory()->create(['during' => '[2026-07-21T10:00:00Z,2026-07-21T18:00:00Z)']);
    $ids = $resources->pluck('id')->all();
    $this->withHeaders(apiKeyHeader());
    $batch = $this->postJson('/api/busy', ['resource_ids' => $ids] + batchWindow())->assertOk()->assertOpenApi('/busy', 'post')->json('busy');
    foreach ($ids as $id) {
        $single = $this->getJson("/api/resources/{$id}/busy?".http_build_query(batchWindow()))->assertOk()->json();
        expect($batch[$id])->toBe($single);
    }
    expect($batch[$ids[0]])->toBe([
        ['starts_at' => '2026-07-21T10:00:00+00:00', 'ends_at' => '2026-07-21T14:00:00+00:00'],
        ['starts_at' => '2026-07-21T16:30:00+00:00', 'ends_at' => '2026-07-21T18:00:00+00:00'],
    ]);
});

it('collapses duplicate ids preserves request order and includes empty lists', function () {
    $ids = Resource::factory()->count(3)->create()->pluck('id')->all();
    $response = $this->withHeaders(apiKeyHeader())->postJson('/api/busy', [
        'resource_ids' => [$ids[2], $ids[0], $ids[2], $ids[1]],
    ] + batchWindow())->assertOk()->assertOpenApi('/busy', 'post');
    expect(array_keys($response->json('busy')))->toBe([$ids[2], $ids[0], $ids[1]])
        ->and(array_values($response->json('busy')))->toBe([[], [], []]);
    expect(json_decode($response->getContent())->busy)->toBeInstanceOf(stdClass::class);
});

it('rejects invalid batch inputs', function (array $overrides, string $field) {
    $id = Resource::factory()->create()->id;
    $this->withHeaders(apiKeyHeader())->postJson('/api/busy', array_replace(['resource_ids' => [$id]] + batchWindow(), $overrides))
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors($field)->assertJsonMissingPath('busy')->assertOpenApi('/busy', 'post');
})->with([
    'empty' => [['resource_ids' => []], 'resource_ids'],
    '101 ids before deduplication' => [['resource_ids' => array_fill(0, 101, 1)], 'resource_ids'],
    'noninteger' => [['resource_ids' => [1.5]], 'resource_ids.0'],
    'numeric string' => [['resource_ids' => ['1']], 'resource_ids.0'],
    'not a list' => [['resource_ids' => ['stylist' => 1]], 'resource_ids'],
    'missing ids' => [['resource_ids' => null], 'resource_ids'],
    'naive from' => [['from' => '2026-07-21T10:00:00'], 'from'],
    'naive to' => [['to' => '2026-07-21T18:00:00'], 'to'],
    'missing from' => [['from' => null], 'from'],
    'missing to' => [['to' => null], 'to'],
    'backward' => [['to' => '2026-07-21T09:00:00Z'], 'to'],
    'equal' => [['to' => '2026-07-21T10:00:00Z'], 'to'],
    'too long' => [['to' => '2027-07-22T10:00:01Z'], 'to'],
]);

it('names all missing ids and returns no partial data or busy queries', function () {
    $id = Resource::factory()->create()->id;
    $this->withHeaders(apiKeyHeader());
    DB::enableQueryLog();
    $response = $this->postJson('/api/busy', ['resource_ids' => [$id, 999998, 999999, 999998]] + batchWindow())
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('resource_ids')->assertJsonMissingPath('busy')->assertOpenApi('/busy', 'post');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(implode(' ', $response->json('errors.resource_ids')))->toContain('999998', '999999');
    expect(array_filter($queries, fn ($q) => preg_match('/from "(bookings|blackouts)"/', $q['query'])))->toBeEmpty();
});

it('accepts exactly 366 elapsed days with offset bounds', function () {
    $id = Resource::factory()->create()->id;
    $this->withHeaders(apiKeyHeader())->postJson('/api/busy', [
        'resource_ids' => [$id], 'from' => '2026-07-21T05:00:00-05:00', 'to' => '2027-07-22T19:00:00+09:00',
    ])->assertOk()->assertJsonPath("busy.{$id}", []);
});

it('uses exactly two overlap data queries for fifty resources and one throttle request', function () {
    $ids = Resource::factory()->count(50)->create()->pluck('id')->all();
    Blackout::factory()->create(['resource_id' => $ids[0], 'during' => '[2026-07-21T11:00:00Z,2026-07-21T12:00:00Z)']);
    config(['api.rate_limit_per_minute' => 1]);
    $this->withHeaders(apiKeyHeader());
    DB::enableQueryLog();
    $this->postJson('/api/busy', ['resource_ids' => $ids] + batchWindow())->assertOk()->assertJsonCount(50, 'busy');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $busyQueries = array_values(array_filter($queries, fn ($q) => preg_match('/from "(bookings|blackouts)"/', $q['query'])));
    expect($busyQueries)->toHaveCount(2);
    foreach (['bookings', 'blackouts'] as $index => $table) {
        expect($busyQueries[$index]['query'])->toContain('from "'.$table.'"', '"resource_id" in (', 'during && ?::tstzrange')
            ->and($busyQueries[$index]['bindings'])->toHaveCount(52);
    }
    $this->postJson('/api/busy', ['resource_ids' => $ids] + batchWindow())->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertOpenApi('/busy', 'post');
});
