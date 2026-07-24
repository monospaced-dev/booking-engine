<?php

use App\Helpers\Interval;
use Carbon\Carbon;

function window(string $start, string $end): array
{
    return ['start' => Carbon::parse($start), 'end' => Carbon::parse($end)];
}

it('returns no busy ranges when there are no exclusions', function () {
    $result = Interval::mergeExclusions(window('09:00', '17:00'), []);

    expect($result)->toBeEmpty();
});

it('clips a single exclusion fully inside the window to itself', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('12:00', '13:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('12:00')
        ->and($result[0]['end']->format('H:i'))->toBe('13:00');
});

it('clips an exclusion that fully covers the window down to the window bounds', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('08:00', '18:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('09:00')
        ->and($result[0]['end']->format('H:i'))->toBe('17:00');
});

it('clips an exclusion that partially hangs outside the window start', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('07:00', '10:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('09:00')
        ->and($result[0]['end']->format('H:i'))->toBe('10:00');
});

it('clips an exclusion that partially hangs outside the window end', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('15:00', '19:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('15:00')
        ->and($result[0]['end']->format('H:i'))->toBe('17:00');
});

it('drops an exclusion entirely outside the window', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('06:00', '08:00'), window('18:00', '19:00')]
    );

    expect($result)->toBeEmpty();
});

it('excludes an exclusion that only touches the window start boundary', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('07:00', '09:00')]
    );

    expect($result)->toBeEmpty();
});

it('excludes an exclusion that only touches the window end boundary', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('17:00', '19:00')]
    );

    expect($result)->toBeEmpty();
});

it('merges two overlapping exclusions into one busy range', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('10:00', '13:00'), window('12:00', '15:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('10:00')
        ->and($result[0]['end']->format('H:i'))->toBe('15:00');
});

it('merges back-to-back exclusions with no gap between them', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('10:00', '12:00'), window('12:00', '14:00')]
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]['start']->format('H:i'))->toBe('10:00')
        ->and($result[0]['end']->format('H:i'))->toBe('14:00');
});

it('keeps separate exclusions apart when a real gap exists between them', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('10:00', '11:00'), window('14:00', '15:00')]
    );

    expect($result)->toHaveCount(2)
        ->and($result[0]['start']->format('H:i'))->toBe('10:00')
        ->and($result[0]['end']->format('H:i'))->toBe('11:00')
        ->and($result[1]['start']->format('H:i'))->toBe('14:00')
        ->and($result[1]['end']->format('H:i'))->toBe('15:00');
});

it('merges exclusions correctly regardless of input order', function () {
    $result = Interval::mergeExclusions(
        window('09:00', '17:00'),
        [window('14:00', '15:00'), window('10:00', '11:00')]
    );

    expect($result)->toHaveCount(2)
        ->and($result[0]['start']->format('H:i'))->toBe('10:00')
        ->and($result[0]['end']->format('H:i'))->toBe('11:00')
        ->and($result[1]['start']->format('H:i'))->toBe('14:00')
        ->and($result[1]['end']->format('H:i'))->toBe('15:00');
});
