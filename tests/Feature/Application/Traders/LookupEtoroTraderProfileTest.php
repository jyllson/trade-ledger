<?php

use App\Application\Traders\FindStoredTraderByUsername;
use App\Application\Traders\LookupEtoroTraderProfile;
use App\Application\Traders\LookupEtoroTraderProfileStopReason;
use App\Application\Traders\TraderUsername;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\TraderProfileMapper;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PROFILE_LOOKUP_URL = 'https://public-api.etoro.com/api/v1/user-info/people*';

function profileLookupUseCase(): LookupEtoroTraderProfile
{
    return new LookupEtoroTraderProfile(
        new EtoroClient(app(Factory::class)),
        new TraderProfileMapper,
        new FindStoredTraderByUsername,
    );
}

/**
 * @return array<string, mixed>
 */
function profileLookupPayload(
    string $gcid = '900001',
    string $username = 'trader_001',
    bool $isPi = true,
    bool $isVerified = true,
    int $country = 1,
    string $languageIsoCode = 'en-US',
): array {
    return [
        'users' => [[
            'gcid' => $gcid,
            'username' => $username,
            'isPi' => $isPi,
            'isVerified' => $isVerified,
            'country' => $country,
            'languageIsoCode' => $languageIsoCode,
        ]],
    ];
}

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value-sentinel',
        'etoro.user_key' => 'test-user-key-value-sentinel',
        'etoro.timeout_seconds' => 5,
        'etoro.connect_timeout_seconds' => 2,
    ]);
    Http::preventStrayRequests();
});

// --- A. Enrichment of a matching stored trader ------------------------------

it('enriches the matching stored trader with exactly the six profile fields, preserving every ranking/status/identity field', function (): void {
    $trader = Trader::factory()->create([
        'external_cid' => '100001',
        'username' => 'trader_001',
        'ranking_type' => 'trader',
        'ranking_sub_type' => 'pi-certified',
        'copiers_count' => 5000,
        'status' => TraderStatus::Watched,
    ]);

    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(gcid: '900001', username: 'trader_001'), 200)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::Completed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->matchedTrader)->not->toBeNull()
        ->and($result->matchedTrader->id)->toBe($trader->id);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->profile_gcid)->toBe('900001')
        ->and($fromDatabase->profile_is_popular_investor)->toBeTrue()
        ->and($fromDatabase->profile_is_verified)->toBeTrue()
        ->and($fromDatabase->profile_country_code)->toBe(1)
        ->and($fromDatabase->profile_language_iso_code)->toBe('en-US')
        ->and($fromDatabase->profile_synced_at)->not->toBeNull()
        // Every ranking/status/identity field is untouched.
        ->and($fromDatabase->external_cid)->toBe('100001')
        ->and($fromDatabase->username)->toBe('trader_001')
        ->and($fromDatabase->ranking_type)->toBe('trader')
        ->and($fromDatabase->ranking_sub_type)->toBe('pi-certified')
        ->and($fromDatabase->copiers_count)->toBe(5000)
        ->and($fromDatabase->status)->toBe(TraderStatus::Watched);
});

it('stores a gcid different from external_cid purely as an observed attribute, with no identity consequence', function (): void {
    $trader = Trader::factory()->create(['external_cid' => '100001', 'username' => 'trader_001']);

    // Deliberately a different value than external_cid, proving no
    // gcid<->external_cid comparison or coupling ever happens.
    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(gcid: '900001', username: 'trader_001'), 200)]);

    profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->profile_gcid)->toBe('900001')
        ->and($fromDatabase->external_cid)->toBe('100001')
        ->and($fromDatabase->profile_gcid)->not->toBe($fromDatabase->external_cid);
});

// --- B. No matching stored trader: Completed, no Trader created ------------

it('completes successfully with no Trader created when the profile username does not match any stored trader', function (): void {
    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(username: 'unknown_trader'), 200)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('unknown_trader'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::Completed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($result->importRun->success_count)->toBe(1)
        ->and($result->matchedTrader)->toBeNull()
        ->and($result->profile)->not->toBeNull()
        ->and($result->importRun->metadata['matched_stored_trader'])->toBeFalse();

    expect(Trader::query()->count())->toBe(0);
});

// --- C. Remote username mismatch: Failed, no mutation/creation -------------

it('fails closed on a profile_identity_mismatch when the mapped profile username differs from the query username, without mutating or creating any Trader', function (): void {
    $trader = Trader::factory()->create(['username' => 'trader_001', 'copiers_count' => 111]);

    // The eToro response reports a DIFFERENT username than was queried —
    // treated as a mismatch, never silently trusted.
    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(username: 'someone_else_entirely'), 200)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::ProfileIdentityMismatch)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->matchedTrader)->toBeNull();

    expect($trader->refresh()->copiers_count)->toBe(111)
        ->and($trader->refresh()->profile_gcid)->toBeNull()
        ->and(Trader::query()->count())->toBe(1);
});

// --- D. Typed failures: config/request/unexpected/mapping, physical request_count ----

it('marks the run Failed for a configuration error, with request_count zero and no HTTP call', function (): void {
    config(['etoro.enabled' => false]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::ConfigurationError)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->request_count)->toBe(0);

    Http::assertNothingSent();
});

it('reports the true physical attempt count on a request failure after 5xx retries, with a sanitized error_summary', function (): void {
    Http::fake([PROFILE_LOOKUP_URL => Http::response(['error' => 'boom-SENTINEL'], 500)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::RequestFailed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->error_summary)->toContain('request_failed')
        ->and($result->importRun->error_summary)->not->toContain('boom-SENTINEL');

    Http::assertSentCount(3);
    expect($result->importRun->request_count)->toBe(3);
});

it('recovers on the final physical retry attempt, with request_count reflecting all attempts', function (): void {
    $callCount = 0;
    Http::fake([
        PROFILE_LOOKUP_URL => function () use (&$callCount) {
            $callCount++;

            if ($callCount < 3) {
                return Http::response(['error' => 'boom'], 503);
            }

            return Http::response(profileLookupPayload(), 200);
        },
    ]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::Completed);

    Http::assertSentCount(3);
    expect($result->importRun->request_count)->toBe(3);
});

it('marks the run Failed for a 2xx unexpected response shape, with a sanitized error_summary', function (): void {
    Http::fake([PROFILE_LOOKUP_URL => Http::response('not-json-array', 200)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::UnexpectedResponse)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->error_summary)->toContain('unexpected_response');
});

it('marks the run Failed for a mapping failure, with a sanitized error_summary', function (): void {
    Http::fake([PROFILE_LOOKUP_URL => Http::response(['users' => []], 200)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->stopReason)->toBe(LookupEtoroTraderProfileStopReason::MappingFailed)
        ->and($result->importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($result->importRun->error_summary)->toContain('mapping_failed');
});

// --- E. ImportRun bookkeeping and sanitization ------------------------------

it('creates exactly one profile ImportRun per call, with sanitized metadata containing only the query username', function (): void {
    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(), 200)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->importRun->source)->toBe('etoro')
        ->and($result->importRun->type)->toBe('profile')
        ->and($result->importRun->metadata['query'])->toBe(['username' => 'trader_001'])
        ->and(ImportRun::query()->where('type', 'profile')->count())->toBe(1);
});

it('sends the request with the exact query username and no Authorization header', function (): void {
    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(), 200)]);

    profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://public-api.etoro.com/api/v1/user-info/people?usernames=trader_001'
            && ! $request->hasHeader('Authorization');
    });
});

it('never leaks credential values in the ImportRun error_summary on a request failure', function (): void {
    Http::fake([PROFILE_LOOKUP_URL => Http::response(['error' => 'boom'], 401)]);

    $result = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($result->importRun->error_summary)
        ->not->toContain('test-api-key-value-sentinel')
        ->not->toContain('test-user-key-value-sentinel');
});

// --- F. Idempotent rerun: no duplicate Traders, two ImportRuns -------------

it('produces no duplicate traders on a repeated lookup, and creates a separate ImportRun audit row each time', function (): void {
    Trader::factory()->create(['username' => 'trader_001']);

    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(), 200)]);

    $first = profileLookupUseCase()->handle(new TraderUsername('trader_001'));
    $second = profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($first->importRun->id)->not->toBe($second->importRun->id)
        ->and(ImportRun::query()->where('type', 'profile')->count())->toBe(2)
        ->and(Trader::query()->count())->toBe(1);
});

it('refreshes profile data and profile_synced_at on a repeated lookup', function (): void {
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    $callCount = 0;
    Http::fake([
        PROFILE_LOOKUP_URL => function () use (&$callCount) {
            $callCount++;

            return Http::response(profileLookupPayload(isPi: $callCount >= 2), 200);
        },
    ]);

    profileLookupUseCase()->handle(new TraderUsername('trader_001'));
    $firstSyncedAt = $trader->refresh()->profile_synced_at;

    expect($trader->profile_is_popular_investor)->toBeFalse()
        ->and($firstSyncedAt)->not->toBeNull();

    profileLookupUseCase()->handle(new TraderUsername('trader_001'));

    expect($trader->refresh()->profile_is_popular_investor)->toBeTrue()
        ->and($trader->profile_synced_at)->not->toBeNull();
});

// --- G. Unexpected persistence failure: rollback + best-effort recovery ----

it('(a) rolls back the profile mutation and rethrows the ORIGINAL exception when the best-effort recovery finalize succeeds', function (): void {
    $trader = Trader::factory()->create(['username' => 'trader_001', 'copiers_count' => 111]);

    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(), 200)]);

    $originalDispatcher = Trader::getEventDispatcher();
    Trader::setEventDispatcher(clone $originalDispatcher);

    $hasThrown = false;

    Trader::saving(function (Trader $savingTrader) use (&$hasThrown, $trader): void {
        if (! $hasThrown && $savingTrader->id === $trader->id && $savingTrader->profile_gcid !== null) {
            $hasThrown = true;

            throw new RuntimeException('Simulated trader profile save failure for test purposes.');
        }
    });

    $thrown = null;

    try {
        try {
            profileLookupUseCase()->handle(new TraderUsername('trader_001'));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }
    } finally {
        Trader::setEventDispatcher($originalDispatcher);
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Simulated trader profile save failure for test purposes.');

    // The trader mutation never committed.
    expect($trader->refresh()->profile_gcid)->toBeNull()
        ->and($trader->copiers_count)->toBe(111);

    $importRun = ImportRun::query()->where('type', 'profile')->latest('id')->firstOrFail();

    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('Simulated trader profile save failure');
});

it('(a2) rolls back the profile mutation and rethrows the ORIGINAL exception when the Completed ImportRun finalize save fails but the recovery Failed save succeeds', function (): void {
    $trader = Trader::factory()->create(['username' => 'trader_001', 'copiers_count' => 111]);

    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(), 200)]);

    $originalDispatcher = ImportRun::getEventDispatcher();
    ImportRun::setEventDispatcher(clone $originalDispatcher);

    // Fails only the first (Completed) finalize save inside the enrichment
    // transaction; the second (recovery, Failed) save is left to succeed.
    ImportRun::saving(function (ImportRun $importRun): void {
        if ($importRun->type === 'profile' && $importRun->status === ImportRunStatus::Completed) {
            throw new RuntimeException('Simulated Completed ImportRun finalize save failure.');
        }
    });

    $thrown = null;

    try {
        try {
            profileLookupUseCase()->handle(new TraderUsername('trader_001'));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Simulated Completed ImportRun finalize save failure.');

    // The Trader profile mutation and the failed Completed finalize save
    // shared one transaction — both rolled back together.
    expect($trader->refresh()->profile_gcid)->toBeNull()
        ->and($trader->copiers_count)->toBe(111);

    $importRun = ImportRun::query()->where('type', 'profile')->latest('id')->firstOrFail();

    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->finished_at)->not->toBeNull();
});

it('(b) propagates the RECOVERY exception instead when the best-effort finalize save itself ALSO fails, and the ImportRun can remain Running', function (): void {
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    Http::fake([PROFILE_LOOKUP_URL => Http::response(profileLookupPayload(), 200)]);

    $originalDispatcher = ImportRun::getEventDispatcher();
    ImportRun::setEventDispatcher(clone $originalDispatcher);

    // Deliberately no "only once" guard: BOTH the normal Completed finalize
    // save and the recovery UnexpectedFailure finalize save must fail, with
    // distinct messages, so the test can prove which one actually
    // propagates out of handle().
    $saveAttempt = 0;

    ImportRun::saving(function (ImportRun $importRun) use (&$saveAttempt): void {
        if ($importRun->type !== 'profile' || $importRun->status === ImportRunStatus::Running) {
            return;
        }

        $saveAttempt++;

        throw new RuntimeException($saveAttempt === 1
            ? 'Simulated ORIGINAL ImportRun finalize save failure.'
            : 'Simulated RECOVERY ImportRun finalize save failure.');
    });

    $thrown = null;

    try {
        try {
            profileLookupUseCase()->handle(new TraderUsername('trader_001'));
        } catch (Throwable $exception) {
            $thrown = $exception;
        }
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }

    // The RECOVERY exception propagates, not the original — proving the
    // documented weaker "recovery save can itself fail and replace the
    // original" contract, not a stronger "original always propagates" one.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Simulated RECOVERY ImportRun finalize save failure.')
        ->and($saveAttempt)->toBe(2);

    $importRun = ImportRun::query()->where('type', 'profile')->latest('id')->firstOrFail();

    // Neither save committed, so the row is left exactly as
    // createImportRun() left it: Running.
    expect($importRun->status)->toBe(ImportRunStatus::Running)
        ->and($importRun->finished_at)->toBeNull();

    // The Trader profile mutation shared the same rolled-back transaction
    // as the failed Completed finalize save.
    expect($trader->refresh()->profile_gcid)->toBeNull();
});
