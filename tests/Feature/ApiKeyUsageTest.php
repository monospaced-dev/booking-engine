<?php

use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;

it('updates key usage only when null or strictly more than sixty seconds old', function (?int $age, bool $updates) {
    $this->travelTo(now()->startOfSecond());
    $headers = apiKeyHeader();
    $key = ApiKey::where('key', ApiKey::hashKey($headers['X-API-Key']))->firstOrFail();
    $previous = $age === null ? null : now()->subSeconds($age);
    $key->update(['last_used_at' => $previous]);
    $queries = [];
    DB::listen(function ($event) use (&$queries) {
        if (str_starts_with(strtolower($event->sql), 'update "api_keys"')) {
            $queries[] = $event->sql;
        }
    });
    $this->withHeaders($headers)->postJson('/api/resources', [])->assertUnprocessable();
    expect($key->fresh()->last_used_at?->toDateTimeString())->toBe(($updates ? now() : $previous)?->toDateTimeString());
    expect($queries)->toHaveCount(1);
    expect($queries[0])->toContain('"last_used_at" is null', '"last_used_at" <');
})->with([[null, true], [61, true], [60, false], [10, false]]);
