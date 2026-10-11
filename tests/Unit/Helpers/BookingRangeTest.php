<?php

use App\Helpers\BookingRange;
use Carbon\CarbonImmutable;

it('formats a half-open range at the existing second precision without mutating its bounds', function () {
    $start = CarbonImmutable::parse('2026-07-21T04:00:00.123456-05:00');
    $end = CarbonImmutable::parse('2026-07-21T05:00:00.654321-05:00');
    expect(BookingRange::literal($start, $end))->toBe('[2026-07-21 04:00:00-05:00,2026-07-21 05:00:00-05:00)');
    expect($start->format('uP'))->toBe('123456-05:00');
});
