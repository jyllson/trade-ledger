<?php

use App\Etoro\FixtureSources\RankingFixtureException;
use App\Etoro\FixtureSources\RankingFixtureFailureReason;
use App\Etoro\FixtureSources\RankingFixtureSource;
use Illuminate\Support\Facades\Http;

/**
 * Offline coverage for RankingFixtureSource. Uses the constructor's path
 * override (test-only) for every failure scenario; the happy path resolves
 * the real canonical resources/fixtures/etoro/rankings.json via
 * resource_path(), which requires the framework container — hence Feature,
 * not Unit.
 */
function rankingFixtureSourceTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'ranking_fixture_source_test_');
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function (): void {
    Http::assertNothingSent();
});

it('loads the canonical resources/fixtures/etoro/rankings.json fixture', function () {
    Http::fake();

    $payload = (new RankingFixtureSource)->load();

    expect($payload)->toHaveKeys(['results', 'pagination'])
        ->and($payload['results'])->toHaveCount(3)
        ->and($payload['pagination'])->toBe(['page' => 1, 'pageSize' => 3, 'totalItems' => 3, 'hasNext' => false]);
});

it('fails closed with SourceUnavailable when the fixture path does not exist', function () {
    Http::fake();

    $missingPath = sys_get_temp_dir().'/ranking_fixture_source_test_missing_'.bin2hex(random_bytes(8)).'.json';

    try {
        (new RankingFixtureSource($missingPath))->load();
        expect(false)->toBeTrue('Expected RankingFixtureException to be thrown.');
    } catch (RankingFixtureException $exception) {
        expect($exception->reason)->toBe(RankingFixtureFailureReason::SourceUnavailable)
            ->and($exception->getMessage())->not->toContain($missingPath);
    }
});

it('fails closed with SourceUnavailable when the fixture path is a directory, not a file', function () {
    Http::fake();

    $directory = sys_get_temp_dir();

    try {
        (new RankingFixtureSource($directory))->load();
        expect(false)->toBeTrue('Expected RankingFixtureException to be thrown.');
    } catch (RankingFixtureException $exception) {
        expect($exception->reason)->toBe(RankingFixtureFailureReason::SourceUnavailable);
    }
});

it('fails closed with SourceUnavailable when the fixture file is unreadable', function () {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        test()->markTestSkipped('Skipped: running as root bypasses file permission checks.');
    }

    Http::fake();

    $path = rankingFixtureSourceTempFile('{"results":[],"pagination":{"page":1,"pageSize":3,"totalItems":0,"hasNext":false}}');
    chmod($path, 0o000);

    try {
        try {
            (new RankingFixtureSource($path))->load();
            expect(false)->toBeTrue('Expected RankingFixtureException to be thrown.');
        } catch (RankingFixtureException $exception) {
            expect($exception->reason)->toBe(RankingFixtureFailureReason::SourceUnavailable)
                ->and($exception->getMessage())->not->toContain($path);
        }
    } finally {
        chmod($path, 0o644);
        unlink($path);
    }
});

it('fails closed with InvalidJson for a syntactically broken JSON file', function () {
    Http::fake();

    $path = rankingFixtureSourceTempFile('{ this is not valid json');

    try {
        try {
            (new RankingFixtureSource($path))->load();
            expect(false)->toBeTrue('Expected RankingFixtureException to be thrown.');
        } catch (RankingFixtureException $exception) {
            expect($exception->reason)->toBe(RankingFixtureFailureReason::InvalidJson)
                ->and($exception->getMessage())->not->toContain($path)
                ->and($exception->getMessage())->not->toContain('this is not valid json');
        }
    } finally {
        unlink($path);
    }
});

it('fails closed with UnexpectedTopLevelShape for a JSON scalar top level', function () {
    Http::fake();

    $path = rankingFixtureSourceTempFile('"just-a-string"');

    try {
        try {
            (new RankingFixtureSource($path))->load();
            expect(false)->toBeTrue('Expected RankingFixtureException to be thrown.');
        } catch (RankingFixtureException $exception) {
            expect($exception->reason)->toBe(RankingFixtureFailureReason::UnexpectedTopLevelShape)
                ->and($exception->getMessage())->not->toContain($path)
                ->and($exception->getMessage())->not->toContain('just-a-string');
        }
    } finally {
        unlink($path);
    }
});

it('fails closed with UnexpectedTopLevelShape for a JSON list top level', function () {
    Http::fake();

    $path = rankingFixtureSourceTempFile('[1,2,3]');

    try {
        try {
            (new RankingFixtureSource($path))->load();
            expect(false)->toBeTrue('Expected RankingFixtureException to be thrown.');
        } catch (RankingFixtureException $exception) {
            expect($exception->reason)->toBe(RankingFixtureFailureReason::UnexpectedTopLevelShape);
        }
    } finally {
        unlink($path);
    }
});
