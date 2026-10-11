<?php

it('rejects invalid route IDs before database binding', function (string $id, string $path) {
    $this->withHeaders(apiKeyHeader())->getJson('/api/'.str_replace('ID', $id, $path))
        ->assertNotFound()->assertJsonPath('code', 'route_not_found');
})->with(['0', '01', '0001', '9223372036854775808', str_repeat('9', 100), '-1', 'abc', '1.2'])
    ->with(['resources/ID/bookings', 'resources/1/bookings/ID', 'resources/1/blackout-dates/ID']);

it('allows an eighteen digit route ID to reach binding', function () {
    $this->withHeaders(apiKeyHeader())->getJson('/api/resources/999999999999999999/bookings')
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});
