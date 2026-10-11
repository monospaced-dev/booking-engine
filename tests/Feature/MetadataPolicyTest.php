<?php

use App\Models\Resource;
use Illuminate\Support\Facades\DB;

function metadataBookingPayload(mixed $metadata): array
{
    return ['starts_at' => '2026-08-03T14:00:00Z', 'ends_at' => '2026-08-03T15:00:00Z', 'metadata' => $metadata];
}

it('retains opaque metadata and replays independent of key order with strict values', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $url = "/api/resources/{$resource->id}/bookings";
    $metadata = ['z_reference' => 'seven', 'appointment_id' => 7, 'a_reference' => 7];
    $created = $this->postJson($url, metadataBookingPayload($metadata), ['Idempotency-Key' => 'metadata'])
        ->assertCreated()->assertOpenApi('/resources/{resource}/bookings', 'post');
    expect($created->json('metadata'))->toEqual($metadata);
    $this->postJson($url, metadataBookingPayload(array_reverse($metadata, true)), ['Idempotency-Key' => 'metadata'])
        ->assertOk()->assertExactJson($created->json());
    $metadata['a_reference'] = '7';
    $this->postJson($url, metadataBookingPayload($metadata), ['Idempotency-Key' => 'metadata'])
        ->assertUnprocessable()->assertJsonPath('code', 'idempotency_mismatch');
});

it('rejects invalid metadata without a write', function (mixed $metadata) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/bookings", metadataBookingPayload($metadata))
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    $this->assertDatabaseCount('bookings', 0);
})->with([
    'list' => [[7]], 'nested' => [['key' => ['nested' => 1]]], 'boolean' => [['key' => true]],
    'float' => [['key' => 7.5]], 'null value' => [['key' => null]], 'null metadata' => [null],
    'bad key' => [['bad-key' => 7]], 'long key' => [[str_repeat('a', 41) => 7]],
    'long value' => [['key' => str_repeat('a', 501)]],
    'too many keys' => [array_fill_keys(range('a', 'u'), 1)],
    'string appointment' => [['appointment_id' => '7']],
]);

it('accepts boundary metadata', function () {
    $resource = Resource::factory()->create();
    $metadata = array_fill_keys(range('a', 's'), 1) + [str_repeat('Z', 40) => str_repeat('é', 500)];
    $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/bookings", metadataBookingPayload($metadata))
        ->assertCreated();
});

it('serializes empty legacy metadata as an object in every booking response without rewriting it', function (mixed $metadata) {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $url = "/api/resources/{$resource->id}/bookings";
    $created = $this->postJson($url, metadataBookingPayload($metadata), ['Idempotency-Key' => 'empty'])->assertCreated();
    $id = $created->json('id');
    DB::table('bookings')->where('id', $id)->update(['metadata' => '[]']);
    $responses = [$created, $this->postJson($url, array_diff_key(metadataBookingPayload([]), ['metadata' => 1]), ['Idempotency-Key' => 'empty'])->assertOk(),
        $this->getJson("$url/$id")->assertOk(),
        $this->patchJson("$url/$id", metadataBookingPayload([]), ['If-Match' => '"1"'])->assertOk()];
    foreach ($responses as $response) {
        expect(json_decode($response->getContent())->metadata)->toBeInstanceOf(stdClass::class);
    }
    expect(json_decode($this->getJson($url)->getContent())[0]->metadata)->toBeInstanceOf(stdClass::class);
    expect(DB::table('bookings')->where('id', $id)->value('metadata'))->toBe('[]');
})->with(['array' => [[]], 'object' => [(object) []]]);

it('preserves whitespace empty strings and numeric object keys', function () {
    $resource = Resource::factory()->create();
    $metadata = (object) ['0' => '', '1' => '  reference  '];
    $response = $this->withHeaders(apiKeyHeader())->postJson("/api/resources/{$resource->id}/bookings", metadataBookingPayload($metadata))
        ->assertCreated();
    expect(json_decode($response->getContent())->metadata)->toEqual($metadata);
    expect(json_decode(DB::table('bookings')->where('id', $response->json('id'))->value('metadata')))->toEqual($metadata);
});
