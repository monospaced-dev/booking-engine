<?php

use App\Models\Resource;
use Illuminate\Support\Facades\Schema;

it('removes the blackout dates table and legacy routes entirely', function () {
    $resource = Resource::factory()->create();
    $this->withHeaders(apiKeyHeader());
    $path = "/api/resources/{$resource->id}/blackout-dates";
    $this->getJson($path)->assertNotFound()->assertJsonPath('code', 'route_not_found');
    $this->postJson($path, ['date' => '2026-07-21'])->assertNotFound()->assertJsonPath('code', 'route_not_found');
    $this->deleteJson($path.'/1')->assertNotFound()->assertJsonPath('code', 'route_not_found');
    expect(Schema::hasTable('blackout_dates'))->toBeFalse();
});
