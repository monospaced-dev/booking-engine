<?php

use App\Models\ApiKey;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

it('throttles authenticated requests across routes per key and resets after a minute', function (string $store) {
    config(['api.rate_limit_per_minute' => 2, 'cache.default' => $store]);
    $headers = apiKeyHeader();
    $this->withHeaders($headers)->postJson('/api/resources', ['external_type' => 'test', 'external_id' => 1])->assertCreated();
    $this->getJson('/api/resources/999/bookings')->assertNotFound();
    $keyId = ApiKey::where('key', ApiKey::hashKey($headers['X-API-Key']))->value('id');
    // A separate limiter reading the configured store must see the same budget.
    $otherLimiter = new RateLimiter(Cache::store($store));
    expect($otherLimiter->attempts(md5('api'.$keyId)))->toBe(2);
    $response = $this->postJson('/api/resources', [])->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After')
        ->assertOpenApi('/resources', 'post');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    $this->getJson('/api/health')->assertOk();
    $this->getJson('/api/ping')->assertNoContent();
    $this->withHeaders(apiKeyHeader())->postJson('/api/resources', [])->assertUnprocessable();
    $this->travel(61)->seconds();
    $this->withHeaders($headers)->postJson('/api/resources', [])->assertUnprocessable();
})->with(['array', 'database']);

it('defaults to 600 requests per minute and rejects invalid keys before throttling', function () {
    expect(config('api.rate_limit_per_minute'))->toBe(600);
    config(['api.rate_limit_per_minute' => 1]);
    $this->withHeaders(['X-API-Key' => 'invalid'])->postJson('/api/resources', [])->assertUnauthorized();
    $this->postJson('/api/resources', [])->assertUnauthorized();
    $this->withHeaders(apiKeyHeader())->postJson('/api/resources', [])->assertUnprocessable();
    $this->postJson('/api/resources', [])->assertStatus(429);
});
