<?php

use App\Helpers\Interval;
use Carbon\Carbon;

function window(string $start, string $end): array
{
    return ['start' => Carbon::parse($start), 'end' => Carbon::parse($end)];
}

it('returns the full window when there are no exclusions', function () {
    $result = Interval::subtract(window('09:00', '17:00'), []);

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('09:00')
        ->and($result[0]['end']->format('H:i'))->toBe('17:00');
});

it('splits the window around a single exclusion in the middle', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('12:00', '13:00')]
    );

    expect($result)->toHaveCount(2)
        ->and($result[0]['start']->format('H:i'))->toBe('09:00')
        ->and($result[0]['end']->format('H:i'))->toBe('12:00')
        ->and($result[1]['start']->format('H:i'))->toBe('13:00')
        ->and($result[1]['end']->format('H:i'))->toBe('17:00');
});

it('returns nothing when exclusion fully covers the window', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('08:00', '18:00')]
    );

    expect($result)->toBeEmpty();
});

it('clips an exclusion that partially hangs outside the window', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('07:00', '10:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('10:00')
        ->and($result[0]['end']->format('H:i'))->toBe('17:00');
});

it('merges back-to-back exclusions with no gap between them', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('10:00', '12:00'), window('12:00', '14:00')]
    );

    expect($result)->toHaveCount(2)
        ->and($result[0]['end']->format('H:i'))->toBe('10:00')
        ->and($result[1]['start']->format('H:i'))->toBe('14:00');
});

it('merges overlapping exclusions', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('10:00', '13:00'), window('12:00', '15:00')]
    );

    expect($result)->toHaveCount(2)
        ->and($result[0]['end']->format('H:i'))->toBe('10:00')
        ->and($result[1]['start']->format('H:i'))->toBe('15:00');
});

it('ignores an exclusion entirely outside the window', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('06:00', '08:00'), window('18:00', '19:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('09:00')
        ->and($result[0]['end']->format('H:i'))->toBe('17:00');
});

it('handles exclusions given out of order', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('14:00', '15:00'), window('10:00', '11:00')]
    );

    expect($result)->toHaveCount(3)
        ->and($result[0]['end']->format('H:i'))->toBe('10:00')
        ->and($result[1]['start']->format('H:i'))->toBe('11:00')
        ->and($result[1]['end']->format('H:i'))->toBe('14:00')
        ->and($result[2]['start']->format('H:i'))->toBe('15:00');
});

it('excludes an exclusion that touches the window start boundary exactly', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('07:00', '09:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('09:00')
        ->and($result[0]['end']->format('H:i'))->toBe('17:00');
});

it('excludes an exclusion that touches the window end boundary exactly', function () {
    $result = Interval::subtract(
        window('09:00', '17:00'),
        [window('17:00', '19:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('09:00')
        ->and($result[0]['end']->format('H:i'))->toBe('17:00');
});

it('builds a window in UTC from a timezone during standard time', function () {
    // Jan 14, 2026 — CST (UTC-6), before DST starts
    $window = Interval::buildWindow('2026-01-14', '09:00:00', '17:00:00', 'America/Chicago');

    expect($window['start']->toIso8601String())->toBe('2026-01-14T15:00:00+00:00')
        ->and($window['end']->toIso8601String())->toBe('2026-01-14T23:00:00+00:00');
});

it('builds a window in UTC from a timezone during daylight saving time', function () {
    // Jul 14, 2026 — CDT (UTC-5), DST in effect
    $window = Interval::buildWindow('2026-07-14', '09:00:00', '17:00:00', 'America/Chicago');

    expect($window['start']->toIso8601String())->toBe('2026-07-14T14:00:00+00:00')
        ->and($window['end']->toIso8601String())->toBe('2026-07-14T22:00:00+00:00');
});

it('builds a window correctly for a timezone ahead of UTC', function () {
    // Tokyo is UTC+9, no DST
    $window = Interval::buildWindow('2026-07-14', '09:00:00', '17:00:00', 'Asia/Tokyo');

    expect($window['start']->toIso8601String())->toBe('2026-07-14T00:00:00+00:00')
        ->and($window['end']->toIso8601String())->toBe('2026-07-14T08:00:00+00:00');
});

it('returns Carbon instances normalized to UTC', function () {
    $window = Interval::buildWindow('2026-07-14', '09:00:00', '17:00:00', 'America/Chicago');

    expect($window['start']->timezone->getName())->toBe('UTC')
        ->and($window['end']->timezone->getName())->toBe('UTC');
});
