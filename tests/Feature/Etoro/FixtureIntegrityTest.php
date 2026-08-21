<?php

/**
 * Offline integrity checks for the fully synthetic fixtures. Three of the
 * four (public-profile.json, performance-history.json, live-portfolio.json)
 * remain test-only assets under tests/Fixtures/Etoro/ — see
 * tests/Fixtures/Etoro/README.md. rankings.json is Checkpoint C's single
 * canonical fixture and lives under resources/fixtures/etoro/ instead
 * (production-resolvable via resource_path(), never duplicated back into
 * tests/Fixtures/Etoro/). No network access, no
 * DTOs, no mappers, and no production calculator are exercised here; this
 * only proves the fixture files themselves are well-formed and leak-free.
 */
const FIXTURE_DIR = __DIR__.'/../../Fixtures/Etoro/';

const RANKING_RESOURCE_FIXTURE_DIR = __DIR__.'/../../../resources/fixtures/etoro/';

function fixtureDirFor(string $name): string
{
    return $name === 'rankings.json' ? RANKING_RESOURCE_FIXTURE_DIR : FIXTURE_DIR;
}

function fixtureJson(string $name): array
{
    return json_decode(file_get_contents(fixtureDirFor($name).$name), true, flags: JSON_THROW_ON_ERROR);
}

function fixtureRaw(string $name): string
{
    return file_get_contents(fixtureDirFor($name).$name);
}

/** Recursively collects every string leaf value in a JSON structure. */
function collectStrings(mixed $data, array &$out): void
{
    if (is_string($data)) {
        $out[] = $data;

        return;
    }
    if (is_array($data)) {
        foreach ($data as $v) {
            collectStrings($v, $out);
        }
    }
}

it('has all four fixture files present and valid JSON', function () {
    foreach (['rankings.json', 'public-profile.json', 'performance-history.json', 'live-portfolio.json'] as $file) {
        $path = fixtureDirFor($file).$file;
        expect($path)->toBeFile();
        json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
});

it('has 3 rankings rows with exactly 53 fields each', function () {
    $rankings = fixtureJson('rankings.json');

    expect($rankings['results'])->toHaveCount(3);

    foreach ($rankings['results'] as $row) {
        expect($row)->toHaveCount(53);
    }
});

it('has 1 profile user with exactly 30 top-level fields', function () {
    $profile = fixtureJson('public-profile.json');

    expect($profile['users'])->toHaveCount(1)
        ->and($profile['users'][0])->toHaveCount(30);
});

it('has 24 monthly and 3 yearly performance records', function () {
    $perf = fixtureJson('performance-history.json');

    expect($perf['monthly'])->toHaveCount(24)
        ->and($perf['yearly'])->toHaveCount(3);
});

it('has ascending monthly timestamps with exactly one deliberate gap', function () {
    $perf = fixtureJson('performance-history.json');
    $timestamps = array_map(fn (array $r) => strtotime($r['timestamp']), $perf['monthly']);

    $sorted = $timestamps;
    sort($sorted);
    expect($timestamps)->toBe($sorted);

    $gaps = [];
    for ($i = 1; $i < count($timestamps); $i++) {
        $gaps[] = ($timestamps[$i] - $timestamps[$i - 1]) / 86400;
    }
    $anomalousGaps = count(array_filter($gaps, fn ($g) => $g > 40));

    expect($anomalousGaps)->toBe(1);
});

it('has trader_001 firstActivity no later than the first performance period', function () {
    $rankings = fixtureJson('rankings.json');
    $perf = fixtureJson('performance-history.json');

    $trader001 = collect($rankings['results'])->firstWhere('username', 'trader_001');
    expect($trader001)->not->toBeNull();

    $firstActivity = strtotime($trader001['firstActivity']);
    $firstMonthly = strtotime($perf['monthly'][0]['timestamp']);
    $lastMonthly = strtotime(end($perf['monthly'])['timestamp']);
    $lastActivity = strtotime($trader001['lastActivity']);

    expect($firstActivity)->toBeLessThanOrEqual($firstMonthly)
        ->and($firstMonthly)->toBeLessThanOrEqual($lastMonthly)
        ->and($lastMonthly)->toBeLessThanOrEqual($lastActivity);
});

it('has 16 positions with exactly 13 fields each and 6 unique instruments', function () {
    $lp = fixtureJson('live-portfolio.json');

    expect($lp['positions'])->toHaveCount(16);

    foreach ($lp['positions'] as $position) {
        expect($position)->toHaveCount(13);
    }

    $uniqueInstruments = collect($lp['positions'])->pluck('instrumentId')->unique();
    expect($uniqueInstruments)->toHaveCount(6);
});

it('has an investmentPct sum decimally equal to 100.0', function () {
    $lp = fixtureJson('live-portfolio.json');

    $sum = round(array_sum(array_column($lp['positions'], 'investmentPct')), 6);

    expect($sum)->toBe(100.0);
});

it('covers exactly 13 positions and 99.3 weight at a $200 copy amount', function () {
    $lp = fixtureJson('live-portfolio.json');
    $weights = array_column($lp['positions'], 'investmentPct');

    $eligible = array_filter($weights, fn ($w) => (200 * $w / 100) >= 1.0);

    expect(count($eligible))->toBe(13)
        ->and(round(array_sum($eligible), 1))->toBe(99.3);
});

it('covers exactly 15 positions and 99.9 weight at a $500 copy amount', function () {
    $lp = fixtureJson('live-portfolio.json');
    $weights = array_column($lp['positions'], 'investmentPct');

    $eligible = array_filter($weights, fn ($w) => (500 * $w / 100) >= 1.0);

    expect(count($eligible))->toBe(15)
        ->and(round(array_sum($eligible), 1))->toBe(99.9);
});

it('covers all 16 positions and 100.0 weight at a $1,000 copy amount', function () {
    $lp = fixtureJson('live-portfolio.json');
    $weights = array_column($lp['positions'], 'investmentPct');

    $eligible = array_filter($weights, fn ($w) => (1000 * $w / 100) >= 1.0);

    expect(count($eligible))->toBe(16)
        ->and(round(array_sum($eligible), 1))->toBe(100.0);
});

it('contains no private storage paths, credential header names, or configured credential values', function () {
    $forbidden = ['storage/app/private', 'x-api-key', 'x-user-key'];

    $apiKey = (string) config('etoro.api_key');
    $userKey = (string) config('etoro.user_key');
    if ($apiKey !== '') {
        $forbidden[] = $apiKey;
    }
    if ($userKey !== '') {
        $forbidden[] = $userKey;
    }

    foreach (['rankings.json', 'public-profile.json', 'performance-history.json', 'live-portfolio.json'] as $file) {
        $raw = fixtureRaw($file);
        foreach ($forbidden as $needle) {
            expect($raw)->not->toContain($needle);
        }
    }
});

it('contains only synthetic .invalid URLs, never a real eToro or third-party domain', function () {
    foreach (['rankings.json', 'public-profile.json', 'performance-history.json', 'live-portfolio.json'] as $file) {
        $data = fixtureJson($file);
        $strings = [];
        collectStrings($data, $strings);

        foreach ($strings as $value) {
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                expect($value)->toContain('example.invalid');
            }
        }
    }
});
