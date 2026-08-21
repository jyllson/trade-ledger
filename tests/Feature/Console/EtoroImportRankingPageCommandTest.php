<?php

use App\Etoro\FixtureSources\RankingFixtureSource;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the command with an explicit buffer (instead of relying on
 * Artisan::output()) so nothing is written to the real STDOUT during tests —
 * same technique as EtoroCopyCoverageCommandTest/EtoroDoctorCommandTest.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{exitCode: int, output: string}
 */
function checkpointCImportRankingPageCall(array $parameters): array
{
    $buffer = new BufferedOutput;

    $exitCode = Artisan::call('etoro:import-ranking-page', $parameters, $buffer);

    return ['exitCode' => $exitCode, 'output' => $buffer->fetch()];
}

function checkpointCImportRankingPageTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'checkpoint_c_import_ranking_page_test_');
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    Http::assertNothingSent();
});

// --- Environment guard: fail closed outside local/testing --------------------

it('refuses to run outside local/testing, before any fixture read or DB write', function (): void {
    Http::fake();

    $originalEnvironment = app()['env'];
    app()['env'] = 'production';

    try {
        ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('development-only')
            ->and(Trader::query()->count())->toBe(0)
            ->and(ImportRun::query()->count())->toBe(0);
    } finally {
        app()['env'] = $originalEnvironment;
    }

    expect(app()['env'])->toBe($originalEnvironment);
});

// --- A. Happy path -----------------------------------------------------------

it('imports the canonical fixture and exits 0 with a sanitized aggregate summary', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('fixture-only, offline')
        ->and($output)->toMatch('/Import run status[ .]+completed/')
        ->and($output)->toMatch('/Fixture pagination[ .]+page=1, pageSize=3/')
        ->and($output)->toMatch('/Entries mapped[ .]+3\b/')
        ->and($output)->toMatch('/Entries succeeded[ .]+3\b/')
        ->and($output)->toMatch('/Entries rejected[ .]+0\b/')
        ->and($output)->not->toContain('trader_001')
        ->and($output)->not->toContain('100001')
        ->and($output)->not->toContain('resources/fixtures/etoro')
        ->and(Trader::query()->count())->toBe(3);
});

// --- B. Invalid input --------------------------------------------------------

it('rejects a blank period with exit 2 and no side effects', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => '   ']);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('period')
        ->and(Trader::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

// --- C. Controlled rejections: partial and all-rejected, both exit 3 --------

it('exits 3 with a partial result when one fixture entry conflicts with an existing trader', function (): void {
    Http::fake();

    Trader::factory()->create(['external_cid' => 'pre-existing-cid', 'username' => 'trader_001']);

    ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(3)
        ->and($output)->toMatch('/Entries succeeded[ .]+2\b/')
        ->and($output)->toMatch('/Entries rejected[ .]+1\b/')
        ->and($output)->toContain('controlled trader identity conflict')
        ->and($output)->not->toContain('trader_001')
        ->and($output)->not->toContain('pre-existing-cid');

    $importRun = ImportRun::query()->latest('id')->firstOrFail();
    expect($importRun->status)->toBe(ImportRunStatus::Partial);
});

it('exits 3 with an all-rejected result when every fixture entry conflicts, even though ImportRunStatus is Failed', function (): void {
    Http::fake();

    Trader::factory()->create(['external_cid' => 'conflict-a', 'username' => 'trader_001']);
    Trader::factory()->create(['external_cid' => 'conflict-b', 'username' => 'trader_002']);
    Trader::factory()->create(['external_cid' => 'conflict-c', 'username' => 'smart_portfolio_001']);

    ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(3)
        ->and($output)->toMatch('/Entries succeeded[ .]+0\b/')
        ->and($output)->toMatch('/Entries rejected[ .]+3\b/');

    $importRun = ImportRun::query()->latest('id')->firstOrFail();
    expect($importRun->status)->toBe(ImportRunStatus::Failed);
});

// --- D. Idempotent rerun -----------------------------------------------------

it('is idempotent across repeated runs: still 3 traders, exit 0 both times', function (): void {
    Http::fake();

    $first = checkpointCImportRankingPageCall(['period' => 'lastYear']);
    $second = checkpointCImportRankingPageCall(['period' => 'lastYear']);

    expect($first['exitCode'])->toBe(0)
        ->and($second['exitCode'])->toBe(0)
        ->and(Trader::query()->count())->toBe(3)
        ->and(ImportRun::query()->count())->toBe(2);
});

// --- E. Missing/corrupt fixture: exit 1 --------------------------------------

it('exits 1 with the generic fatal message when the fixture is missing, without leaking the path', function (): void {
    Http::fake();

    $missingPath = sys_get_temp_dir().'/checkpoint_c_missing_'.bin2hex(random_bytes(8)).'.json';
    app()->instance(RankingFixtureSource::class, new RankingFixtureSource($missingPath));

    ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Offline fixture ranking-page import failed.')
        ->and($output)->not->toContain($missingPath)
        ->and(Trader::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

it('exits 1 with the generic fatal message when the fixture is invalid JSON', function (): void {
    Http::fake();

    $path = checkpointCImportRankingPageTempFile('{ not valid json');
    app()->instance(RankingFixtureSource::class, new RankingFixtureSource($path));

    try {
        ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Offline fixture ranking-page import failed.')
            ->and($output)->not->toContain($path)
            ->and($output)->not->toContain('not valid json')
            ->and(Trader::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

// --- F. Mapping failure: exit 1, no writes -----------------------------------

it('exits 1 with the generic fatal message and no writes when the fixture payload fails mapping', function (): void {
    Http::fake();

    $path = checkpointCImportRankingPageTempFile('{"pagination":{"page":1,"pageSize":3,"totalItems":0,"hasNext":false}}');
    app()->instance(RankingFixtureSource::class, new RankingFixtureSource($path));

    try {
        ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Offline fixture ranking-page import failed.')
            ->and($output)->not->toContain($path)
            ->and(Trader::query()->count())->toBe(0)
            ->and(ImportRun::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

// --- G. Pagination contract mismatch: exit 1, no writes ----------------------

it('exits 1 with the generic fatal message and no writes when the injected fixture pagination does not match the fixed page/pageSize contract', function (): void {
    Http::fake();

    $path = checkpointCImportRankingPageTempFile('{"results":[],"pagination":{"page":2,"pageSize":3,"totalItems":0,"hasNext":false}}');
    app()->instance(RankingFixtureSource::class, new RankingFixtureSource($path));

    try {
        ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Offline fixture ranking-page import failed.')
            ->and($output)->not->toContain('page=2')
            ->and(Trader::query()->count())->toBe(0)
            ->and(ImportRun::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

// --- H. Persistence exception: exit 1, original message never shown --------

it('exits 1 with the generic fatal message when persistence unexpectedly fails, never the original exception message', function (): void {
    Http::fake();

    $originalDispatcher = ImportRun::getEventDispatcher();
    ImportRun::setEventDispatcher(clone $originalDispatcher);

    $hasThrown = false;

    ImportRun::saving(function (ImportRun $importRun) use (&$hasThrown): void {
        if (! $hasThrown && $importRun->status !== ImportRunStatus::Running) {
            $hasThrown = true;

            throw new RuntimeException('Simulated ImportRun finalization failure for test purposes.');
        }
    });

    try {
        ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => 'lastYear']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Offline fixture ranking-page import failed.')
            ->and($output)->not->toContain('Simulated ImportRun finalization failure')
            ->and(Trader::query()->count())->toBe(0);
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }
});

// --- I. Input/metadata contract: trimmed period, exact RankingQuery metadata --

it('trims surrounding whitespace from period and records the exact query metadata contract, with no identity in output', function (): void {
    Http::fake();

    ['exitCode' => $exitCode, 'output' => $output] = checkpointCImportRankingPageCall(['period' => '  lastYear  ']);

    expect($exitCode)->toBe(0);

    $importRun = ImportRun::query()->latest('id')->firstOrFail();

    expect($importRun->metadata['query'])->toBe([
        'period' => 'lastYear',
        'page' => 1,
        'pageSize' => 3,
        'sort' => null,
        'country' => null,
    ]);

    expect($output)->not->toContain('trader_001')
        ->and($output)->not->toContain('trader_002')
        ->and($output)->not->toContain('smart_portfolio_001')
        ->and($output)->not->toContain('100001')
        ->and($output)->not->toContain('100002')
        ->and($output)->not->toContain('100003');
});
