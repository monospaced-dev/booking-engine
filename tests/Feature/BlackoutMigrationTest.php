<?php

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills local blackout dates into exact instants including both Chicago DST transitions', function () {
    foreach (['2026_10_11_210000_remove_resource_timezone.php', '2026_10_11_200000_drop_blackout_dates.php'] as $file) {
        (require database_path('migrations/'.$file))->down();
    }
    Schema::dropIfExists('blackouts');
    $cases = [
        ['America/Chicago', '2026-07-21', '12:00', '13:00', '2026-07-21T17:00:00+00:00', '2026-07-21T18:00:00+00:00'],
        ['Asia/Tokyo', '2026-07-22', '06:00', '07:00', '2026-07-21T21:00:00+00:00', '2026-07-21T22:00:00+00:00'],
        ['America/Chicago', '2026-07-21', null, null, '2026-07-21T05:00:00+00:00', '2026-07-22T05:00:00+00:00'],
        ['America/Chicago', '2026-03-08', null, null, '2026-03-08T06:00:00+00:00', '2026-03-09T05:00:00+00:00'],
        ['America/Chicago', '2026-11-01', null, null, '2026-11-01T05:00:00+00:00', '2026-11-02T06:00:00+00:00'],
    ];
    foreach ($cases as $i => [$timezone, $date, $start, $end]) {
        $resource = DB::table('resources')->insertGetId(['tenant_id' => DB::table('tenants')->where('name', 'default')->value('id'), 'external_type' => 'migration', 'external_id' => $i, 'timezone' => $timezone]);
        DB::table('blackout_dates')->insert(['resource_id' => $resource, 'date' => $date, 'start_time' => $start, 'end_time' => $end,
            'note' => 'legacy-'.$i, 'created_at' => '2026-01-01 01:02:03', 'updated_at' => '2026-01-02 01:02:03']);
    }
    (require database_path('migrations/2026_10_11_190000_create_blackouts.php'))->up();
    $rows = DB::select('SELECT *, lower(during) AS start, upper(during) AS finish FROM blackouts ORDER BY id');
    (require database_path('migrations/2026_10_11_200000_drop_blackout_dates.php'))->up();
    (require database_path('migrations/2026_10_11_210000_remove_resource_timezone.php'))->up();
    expect(Schema::hasTable('blackout_dates'))->toBeFalse();
    expect(Schema::hasColumn('resources', 'timezone'))->toBeFalse();
    expect(DB::select('SELECT *, lower(during) AS start, upper(during) AS finish FROM blackouts ORDER BY id'))->toEqual($rows);
    expect($rows)->toHaveCount(5);
    foreach ($rows as $i => $row) {
        expect(Carbon::parse($row->start)->utc()->toIso8601String())->toBe($cases[$i][4]);
        expect(Carbon::parse($row->finish)->utc()->toIso8601String())->toBe($cases[$i][5]);
        expect($row->note)->toBe('legacy-'.$i);
        expect($row->created_at)->toBe('2026-01-01 01:02:03');
        expect($row->updated_at)->toBe('2026-01-02 01:02:03');
        expect($row->resource_id)->toBe(DB::table('resources')->where('external_type', 'migration')->where('external_id', $i)->value('id'));
        expect($row->during)->toStartWith('[')->toEndWith(')');
    }
});

it('rejects empty and infinite blackout ranges', function (string $range) {
    $resource = App\Models\Resource::factory()->create();
    try {
        DB::transaction(fn () => DB::insert('INSERT INTO blackouts (resource_id, during) VALUES (?, ?::tstzrange)', [$resource->id, $range]));
        $this->fail('Invalid blackout range accepted');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
        expect($e->getMessage())->toContain('blackouts_during_finite_nonempty');
    }
})->with(['empty', '(,2026-01-01)', '[2026-01-01,)', '[-infinity,2026-01-01)', '[2026-01-01,infinity)']);

it('allows overlapping blackouts and cascades resource deletion', function () {
    $resource = App\Models\Resource::factory()->create();
    for ($i = 0; $i < 2; $i++) {
        DB::insert("INSERT INTO blackouts (resource_id, during) VALUES (?, '[2026-01-01,2026-01-02)'::tstzrange)", [$resource->id]);
    }
    expect(DB::table('blackouts')->count())->toBe(2);
    $resource->delete();
    expect(DB::table('blackouts')->count())->toBe(0);
    $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'blackouts_resource_during_gist'");
    expect($index->indexdef)->toContain('USING gist (resource_id, during)');
});
