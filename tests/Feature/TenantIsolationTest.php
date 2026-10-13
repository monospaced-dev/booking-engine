<?php

use App\Actions\CreateBooking;
use App\Actions\GetBusyRanges;
use App\Models\ApiKey;
use App\Models\Blackout;
use App\Models\Resource;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function tenantSnapshot(): array
{
    return collect(['tenants', 'resources', 'bookings', 'blackouts', 'expired_booking_holds'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy($table === 'expired_booking_holds' ? 'booking_id' : 'id')->get()->toJson()])->all();
}

it('sweeps every resource and child route for foreign ids without changing data', function () {
    $a = Tenant::create(['name' => 'A']);
    $b = Tenant::create(['name' => 'B']);
    $resource = Resource::factory()->create(['tenant_id' => $a->id]);
    $own = Resource::factory()->create(['tenant_id' => $b->id]);
    $payload = ['starts_at' => '2026-11-01T09:00:00Z', 'ends_at' => '2026-11-01T10:00:00Z'];
    $aHeaders = apiKeyHeader($a->id);
    $hold = $this->postJson("/api/resources/{$resource->id}/bookings", $payload + ['hold_seconds' => 60], $aHeaders)->assertCreated()->json('id');
    $blackout = Blackout::factory()->create(['resource_id' => $resource->id]);
    // Expired receipts must also stay invisible under both foreign and own parents.
    DB::table('expired_booking_holds')->insert(['booking_id' => 900001, 'resource_id' => $resource->id]);
    $bHeaders = apiKeyHeader($b->id) + ['If-Match' => '"1"', 'Idempotency-Key' => 'sweep'];
    $before = tenantSnapshot();
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && array_intersect($route->parameterNames(), ['resource', 'booking', 'blackout']));
    expect($routes)->not->toBeEmpty();
    foreach ($routes as $route) {
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $parents = in_array('booking', $route->parameterNames()) || in_array('blackout', $route->parameterNames()) ? [$resource->id, $own->id] : [$resource->id];
            foreach ($parents as $parent) {
                $uri = strtr('/'.$route->uri(), ['{resource}' => $parent, '{booking}' => $hold, '{blackout}' => $blackout->id]);
                $body = $payload + ['from' => $payload['starts_at'], 'to' => $payload['ends_at']];
                $response = $this->json($method, $uri, $body, $bHeaders);
                $response->assertNotFound()->assertExactJson(['message' => 'Resource not found.', 'code' => 'not_found']);
                expect(tenantSnapshot())->toEqual($before);
                // Empty/invalid inputs cannot reveal validation or precondition details.
                $this->json($method, $uri, [], array_replace($bHeaders, ['If-Match' => 'invalid']))->assertNotFound()->assertJsonPath('code', 'not_found');
                expect(tenantSnapshot())->toEqual($before);
            }
        }
    }
    foreach ([$resource->id, $own->id] as $parent) {
        $this->postJson("/api/resources/{$parent}/bookings/900001/confirm", [], $bHeaders)->assertNotFound()->assertJsonPath('code', 'not_found');
    }
    expect(tenantSnapshot())->toEqual($before);
});

it('finds external identities only within the authenticated tenant and ignores client tenant input', function () {
    $a = Tenant::create(['name' => 'A']);
    $b = Tenant::create(['name' => 'B']);
    $identity = ['external_type' => 'stylist', 'external_id' => 1];
    $first = $this->postJson('/api/resources', $identity, apiKeyHeader($a->id))->assertCreated();
    $headers = apiKeyHeader($b->id);
    $second = $this->postJson('/api/resources', $identity + ['tenant_id' => $a->id], $headers)->assertCreated()->assertOpenApi('/resources', 'post');
    expect($second->json('id'))->not->toBe($first->json('id'));
    $this->assertDatabaseHas('resources', ['id' => $second->json('id'), 'tenant_id' => $b->id]);
    $this->postJson('/api/resources', $identity, $headers)->assertOk()->assertJsonPath('id', $second->json('id'))->assertJsonPath('created', false);
    // A second key in B sees the same identity.
    $this->postJson('/api/resources', $identity, apiKeyHeader($b->id))->assertOk()->assertJsonPath('id', $second->json('id'));
});

it('treats foreign batch ids exactly like missing ids and never queries their busy data', function () {
    $b = Tenant::create(['name' => 'B']);
    $foreign = Resource::factory()->create();
    $own = Resource::factory()->create(['tenant_id' => $b->id]);
    Blackout::factory()->create(['resource_id' => $foreign->id, 'during' => '[2026-11-01T09:00:00Z,2026-11-01T10:00:00Z)']);
    app(CreateBooking::class)->handle($foreign->id, $foreign->external_type, $foreign->external_id, Carbon::parse('2026-11-01T09:00:00Z'), Carbon::parse('2026-11-01T10:00:00Z'));
    $headers = apiKeyHeader($b->id);
    $window = ['from' => '2026-11-01T09:00:00Z', 'to' => '2026-11-01T10:00:00Z'];
    $this->postJson('/api/busy', ['resource_ids' => [$own->id, $foreign->id, $foreign->id]] + $window, $headers)
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.resource_ids', ['Unknown resource IDs: '.$foreign->id.'.'])->assertJsonMissingPath('busy')->assertOpenApi('/busy', 'post');
    $this->postJson('/api/busy', ['resource_ids' => [$own->id, $foreign->id, 999999]] + $window, $headers)
        ->assertUnprocessable()->assertJsonPath('errors.resource_ids', ['Unknown resource IDs: '.$foreign->id.', 999999.']);
    $this->postJson('/api/busy', ['resource_ids' => [$own->id]] + $window, $headers)->assertOk()->assertJsonPath('busy.'.$own->id, []);
    // The action itself filters foreign resource IDs in both overlap queries.
    DB::enableQueryLog();
    $busy = app(GetBusyRanges::class)->handleBatch([$own->id, $foreign->id], Carbon::parse($window['from']), Carbon::parse($window['to']), $b->id);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($busy[$foreign->id])->toBe([]);
    foreach (array_filter($queries, fn ($q) => preg_match('/from "(bookings|blackouts)"/', $q['query'])) as $query) {
        expect($query['query'])->toContain('"tenant_id" = ?');
    }
});

it('keeps hold replay conflicts confirmation and cleanup isolated between tenants', function () {
    $a = Tenant::create(['name' => 'A']);
    $b = Tenant::create(['name' => 'B']);
    $payload = ['starts_at' => '2026-11-01T09:00:00Z', 'ends_at' => '2026-11-01T10:00:00Z', 'hold_seconds' => 60];
    $holds = [];
    foreach ([$a, $b] as $tenant) {
        $resource = Resource::factory()->create(['tenant_id' => $tenant->id, 'external_type' => 'stylist', 'external_id' => 1]);
        $headers = apiKeyHeader($tenant->id) + ['Idempotency-Key' => 'same-key'];
        $path = "/api/resources/{$resource->id}/bookings";
        $hold = $this->postJson($path, $payload, $headers)->assertCreated()->assertJsonPath('status', 'held');
        $this->postJson($path, $payload, $headers)->assertOk()->assertJsonPath('id', $hold->json('id'))->assertJsonPath('expires_at', $hold->json('expires_at'));
        $this->postJson($path, array_replace($payload, ['hold_seconds' => 61]), $headers)->assertUnprocessable()->assertJsonPath('code', 'idempotency_mismatch');
        $this->postJson($path, $payload, ['X-API-Key' => $headers['X-API-Key']])->assertConflict()->assertJsonPath('code', 'booking_conflict');
        $holds[] = [$path, $headers, $hold->json('id')];
    }
    expect($holds[0][2])->not->toBe($holds[1][2]);
    [$aPath, $aHeaders, $aHold] = $holds[0];
    [$bPath, $bHeaders, $bHold] = $holds[1];
    DB::update('UPDATE bookings SET expires_at = now() WHERE id = ?', [$aHold]);
    $this->postJson($bPath.'/'.$bHold.'/confirm', [], $bHeaders)->assertOk()->assertJsonPath('status', 'confirmed');
    $this->assertDatabaseHas('bookings', ['id' => $aHold, 'status' => 'held']);
    $this->postJson($aPath, $payload, $aHeaders)->assertCreated();
    $this->assertDatabaseMissing('bookings', ['id' => $aHold]);
    $this->postJson($bPath, $payload, $bHeaders)->assertOk()->assertJsonPath('id', $bHold)->assertJsonPath('status', 'confirmed');
});

it('backfills legacy keys and resources into default and preserves their API behavior', function () {
    $migration = require database_path('migrations/2026_10_13_180000_add_tenants.php');
    $migration->down();
    $raw = 'legacy-key';
    $key = DB::table('api_keys')->insertGetId(['name' => 'legacy', 'key' => ApiKey::hashKey($raw), 'is_active' => true]);
    $inactive = DB::table('api_keys')->insertGetId(['name' => 'inactive', 'key' => ApiKey::hashKey('inactive'), 'is_active' => false]);
    $id = DB::table('resources')->insertGetId(['external_type' => 'stylist', 'external_id' => 1]);
    DB::insert("INSERT INTO bookings (resource_id, external_type, external_id, during) VALUES (?, 'stylist', 1, '[2026-11-01T09:00:00Z,2026-11-01T10:00:00Z)')", [$id]);
    $migration->up();
    $default = Tenant::where('name', 'default')->sole();
    $this->assertDatabaseHas('resources', ['id' => $id, 'tenant_id' => $default->id]);
    foreach ([$key, $inactive] as $keyId) {
        $this->assertDatabaseHas('api_keys', ['id' => $keyId, 'tenant_id' => $default->id]);
    }
    $headers = ['X-API-Key' => $raw];
    $this->postJson('/api/resources', ['external_type' => 'stylist', 'external_id' => 1], $headers)->assertOk()->assertJsonPath('id', $id);
    $this->getJson("/api/resources/{$id}/bookings", $headers)->assertOk()->assertJsonCount(1);
    $this->postJson("/api/resources/{$id}/bookings", ['starts_at' => '2026-11-01T11:00:00Z', 'ends_at' => '2026-11-01T12:00:00Z'], $headers)->assertCreated();
    $this->getJson("/api/resources/{$id}/bookings", ['X-API-Key' => 'inactive'])->assertUnauthorized();
});

it('requires tenant foreign keys and tenant scoped resource uniqueness in Postgres', function () {
    $resource = Resource::factory()->create();
    foreach ([['resources', ['tenant_id' => null], '23502'], ['resources', ['tenant_id' => 999999], '23503'], ['api_keys', ['tenant_id' => null, 'name' => 'bad', 'key' => 'bad'], '23502'], ['api_keys', ['tenant_id' => 999999, 'name' => 'bad', 'key' => 'bad'], '23503']] as [$table, $values, $state]) {
        try {
            DB::transaction(fn () => $table === 'resources' ? DB::table($table)->where('id', $resource->id)->update($values) : DB::table($table)->insert($values));
            $this->fail('Invalid tenant accepted');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe($state);
        }
    }
    try {
        DB::transaction(fn () => Resource::create($resource->only(['tenant_id', 'external_type', 'external_id'])));
        $this->fail('Duplicate resource identity accepted');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23505');
    }
});

it('creates tenants and requires an existing tenant for generated keys while retaining key lifecycle commands', function () {
    $this->artisan('tenant:create', ['name' => 'B'])->assertSuccessful();
    $this->artisan('tenant:create', ['name' => 'B'])->assertFailed();
    $this->artisan('tenant:create', ['name' => ''])->assertFailed();
    $this->artisan('apikey:generate', ['--name' => 'B-key'])->assertFailed();
    $this->artisan('apikey:generate', ['--name' => 'B-key', '--tenant' => 'missing'])->assertFailed();
    expect(ApiKey::count())->toBe(0);
    $this->artisan('apikey:generate', ['--name' => 'B-key', '--tenant' => 'B'])->assertSuccessful();
    $key = ApiKey::where('name', 'B-key')->sole();
    expect($key->tenant_id)->toBe(Tenant::where('name', 'B')->value('id'));
    $this->artisan('apikey:deactivate', ['--name' => 'B-key'])->assertSuccessful();
    expect($key->fresh()->is_active)->toBeFalse();
    $this->artisan('apikey:reactivate', ['--name' => 'B-key'])->assertSuccessful();
    expect($key->fresh()->is_active)->toBeTrue();
});

it('binds resource parameters centrally even when a new route has no model type hint', function () {
    Route::middleware(['api', 'auth.apikey'])->get('/api/tenant-binding-probe/{resource}', fn ($resource) => response()->json(['id' => $resource->id]));
    $foreign = Resource::factory()->create();
    $b = Tenant::create(['name' => 'B']);
    $own = Resource::factory()->create(['tenant_id' => $b->id]);
    $headers = apiKeyHeader($b->id);
    $this->getJson('/api/tenant-binding-probe/'.$own->id, $headers)->assertOk()->assertJsonPath('id', $own->id);
    $this->getJson('/api/tenant-binding-probe/'.$foreign->id, $headers)->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->getJson('/api/tenant-binding-probe/'.$own->id, ['X-API-Key' => 'invalid'])->assertUnauthorized();
    // An accidentally unauthenticated resource route also fails closed.
    Route::middleware('api')->get('/api/unauthed-binding-probe/{resource}', fn () => response()->json(['leaked' => true]));
    $this->getJson('/api/unauthed-binding-probe/'.$own->id, $headers)->assertNotFound();
});

it('rolls back atomically when tenant identities cannot regain global uniqueness', function () {
    $resource = Resource::factory()->create();
    $b = Tenant::create(['name' => 'B']);
    Resource::factory()->create(['tenant_id' => $b->id] + $resource->only(['external_type', 'external_id']));
    $before = tenantSnapshot();
    try {
        DB::transaction(fn () => (require database_path('migrations/2026_10_13_180000_add_tenants.php'))->down());
        $this->fail('Rollback accepted duplicate global identities');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23505');
    }
    expect(tenantSnapshot())->toEqual($before);
});
