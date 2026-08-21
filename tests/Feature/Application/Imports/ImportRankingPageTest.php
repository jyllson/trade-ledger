<?php

use App\Application\Imports\ImportRankingPage;
use App\Etoro\Data\RankingEntry;
use App\Etoro\Data\RankingPage;
use App\Etoro\Data\RankingPagination;
use App\Etoro\RankingQuery;
use App\Models\ImportRun;
use App\Models\ImportRunFailure;
use App\Models\ImportRunFailureReason;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Support\Carbon;

function importRankingPageEntry(
    string $cid = '100001',
    string $username = 'trader_001',
    string $type = 'trader',
    string $subType = 'pi-certified',
    int $copiers = 5000,
): RankingEntry {
    return new RankingEntry(
        cid: $cid,
        username: $username,
        type: $type,
        subType: $subType,
        copiers: $copiers,
    );
}

/**
 * @param  list<RankingEntry>  $entries
 */
function importRankingPagePage(array $entries, ?int $totalItems = null): RankingPage
{
    return new RankingPage(
        entries: $entries,
        pagination: new RankingPagination(
            page: 1,
            pageSize: 20,
            totalItems: $totalItems ?? count($entries),
            hasNext: false,
        ),
    );
}

function importRankingPageQuery(): RankingQuery
{
    return new RankingQuery(period: 'lastYear', page: 1, pageSize: 20, sort: '-copiers', country: 'US');
}

afterEach(function (): void {
    Carbon::setTestNow();
});

// --- New trader creation -----------------------------------------------

it('creates a candidate trader from a new ranking entry with the correct fields', function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');

    $page = importRankingPagePage([importRankingPageEntry()]);

    (new ImportRankingPage)->handle($page, importRankingPageQuery());

    $trader = Trader::query()->where('external_cid', '100001')->firstOrFail();

    expect($trader->external_cid)->toBe('100001')
        ->and($trader->username)->toBe('trader_001')
        ->and($trader->ranking_type)->toBe('trader')
        ->and($trader->ranking_sub_type)->toBe('pi-certified')
        ->and($trader->copiers_count)->toBe(5000)
        ->and($trader->status)->toBe(TraderStatus::Candidate)
        ->and($trader->first_seen_at->equalTo(Carbon::parse('2026-08-10 12:00:00')))->toBeTrue()
        ->and($trader->last_seen_at->equalTo(Carbon::parse('2026-08-10 12:00:00')))->toBeTrue();
});

it('stores external_cid as a string, not a numeric reinterpretation', function (): void {
    $page = importRankingPagePage([importRankingPageEntry(cid: '007')]);

    (new ImportRankingPage)->handle($page, importRankingPageQuery());

    $trader = Trader::query()->where('username', 'trader_001')->firstOrFail();

    expect($trader->external_cid)->toBe('007')->toBeString();
});

// --- Idempotent re-import ------------------------------------------------

it('does not create duplicate trader rows on a repeated identical import', function (): void {
    $page = importRankingPagePage([importRankingPageEntry()]);

    (new ImportRankingPage)->handle($page, importRankingPageQuery());
    (new ImportRankingPage)->handle($page, importRankingPageQuery());

    expect(Trader::query()->count())->toBe(1);
});

it('updates ranking data and last_seen_at on a repeated import', function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');
    $firstPage = importRankingPagePage([importRankingPageEntry(copiers: 5000, type: 'trader', subType: 'pi-certified')]);
    (new ImportRankingPage)->handle($firstPage, importRankingPageQuery());

    Carbon::setTestNow('2026-08-11 09:00:00');
    $secondPage = importRankingPagePage([importRankingPageEntry(copiers: 5200, type: 'trader', subType: 'pi-elite')]);
    (new ImportRankingPage)->handle($secondPage, importRankingPageQuery());

    $trader = Trader::query()->where('external_cid', '100001')->firstOrFail();

    expect($trader->copiers_count)->toBe(5200)
        ->and($trader->ranking_sub_type)->toBe('pi-elite')
        ->and($trader->last_seen_at->equalTo(Carbon::parse('2026-08-11 09:00:00')))->toBeTrue();
});

it('does not change first_seen_at on a repeated import', function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');
    (new ImportRankingPage)->handle(importRankingPagePage([importRankingPageEntry()]), importRankingPageQuery());

    Carbon::setTestNow('2026-08-11 09:00:00');
    (new ImportRankingPage)->handle(importRankingPagePage([importRankingPageEntry()]), importRankingPageQuery());

    $trader = Trader::query()->where('external_cid', '100001')->firstOrFail();

    expect($trader->first_seen_at->equalTo(Carbon::parse('2026-08-10 12:00:00')))->toBeTrue();
});

it('preserves an existing watched status across a repeated import', function (): void {
    $trader = Trader::factory()->create([
        'external_cid' => '100001',
        'username' => 'trader_001',
        'status' => TraderStatus::Watched,
    ]);

    (new ImportRankingPage)->handle(importRankingPagePage([importRankingPageEntry()]), importRankingPageQuery());

    expect($trader->refresh()->status)->toBe(TraderStatus::Watched);
});

it('preserves an existing ignored status across a repeated import', function (): void {
    $trader = Trader::factory()->create([
        'external_cid' => '100001',
        'username' => 'trader_001',
        'status' => TraderStatus::Ignored,
    ]);

    (new ImportRankingPage)->handle(importRankingPagePage([importRankingPageEntry()]), importRankingPageQuery());

    expect($trader->refresh()->status)->toBe(TraderStatus::Ignored);
});

// --- Identity conflicts ----------------------------------------------------

it('treats a matching external_cid with a different username as a conflict without mutating the row', function (): void {
    $trader = Trader::factory()->create([
        'external_cid' => '100001',
        'username' => 'original_username',
        'copiers_count' => 111,
    ]);

    $conflictingEntry = importRankingPageEntry(cid: '100001', username: 'different_username');

    $importRun = (new ImportRankingPage)->handle(importRankingPagePage([$conflictingEntry]), importRankingPageQuery());

    expect($trader->refresh()->username)->toBe('original_username')
        ->and($trader->copiers_count)->toBe(111)
        ->and(Trader::query()->count())->toBe(1)
        ->and($importRun->failure_count)->toBe(1)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('100001')
        ->and($importRun->error_summary)->not->toContain('original_username')
        ->and($importRun->error_summary)->not->toContain('different_username');
});

it('treats a matching username with a different external_cid as a conflict without mutating the row', function (): void {
    $trader = Trader::factory()->create([
        'external_cid' => 'original_cid',
        'username' => 'trader_001',
        'copiers_count' => 111,
    ]);

    $conflictingEntry = importRankingPageEntry(cid: 'different_cid', username: 'trader_001');

    $importRun = (new ImportRankingPage)->handle(importRankingPagePage([$conflictingEntry]), importRankingPageQuery());

    expect($trader->refresh()->external_cid)->toBe('original_cid')
        ->and($trader->copiers_count)->toBe(111)
        ->and(Trader::query()->count())->toBe(1)
        ->and($importRun->failure_count)->toBe(1)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('original_cid')
        ->and($importRun->error_summary)->not->toContain('different_cid')
        ->and($importRun->error_summary)->not->toContain('trader_001');
});

it('treats a cid and username that belong to two different rows as a conflict without mutating either row', function (): void {
    $traderA = Trader::factory()->create(['external_cid' => 'cid-a', 'username' => 'username-a']);
    $traderB = Trader::factory()->create(['external_cid' => 'cid-b', 'username' => 'username-b']);

    $conflictingEntry = importRankingPageEntry(cid: 'cid-a', username: 'username-b');

    $importRun = (new ImportRankingPage)->handle(importRankingPagePage([$conflictingEntry]), importRankingPageQuery());

    expect($traderA->refresh()->username)->toBe('username-a')
        ->and($traderB->refresh()->external_cid)->toBe('cid-b')
        ->and(Trader::query()->count())->toBe(2)
        ->and($importRun->failure_count)->toBe(1)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('cid-a')
        ->and($importRun->error_summary)->not->toContain('cid-b')
        ->and($importRun->error_summary)->not->toContain('username-a')
        ->and($importRun->error_summary)->not->toContain('username-b');
});

// --- Duplicates and conflicts within a single page --------------------------

it('collapses a consistent duplicate entry (same cid and username) into a single trader write, using the last occurrence\'s data', function (): void {
    $firstOccurrence = importRankingPageEntry(cid: '100001', username: 'trader_001', copiers: 100, subType: 'pi-certified');
    $secondOccurrence = importRankingPageEntry(cid: '100001', username: 'trader_001', copiers: 200, subType: 'pi-elite');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$firstOccurrence, $secondOccurrence]),
        importRankingPageQuery(),
    );

    expect(Trader::query()->count())->toBe(1);

    $trader = Trader::query()->where('external_cid', '100001')->firstOrFail();

    expect($trader->copiers_count)->toBe(200)
        ->and($trader->ranking_sub_type)->toBe('pi-elite')
        ->and($importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($importRun->success_count)->toBe(2)
        ->and($importRun->failure_count)->toBe(0)
        ->and($importRun->error_summary)->toBeNull();
});

it('collapses the same RankingEntry instance appearing twice into a single trader write', function (): void {
    $entry = importRankingPageEntry();

    $importRun = (new ImportRankingPage)->handle(importRankingPagePage([$entry, $entry]), importRankingPageQuery());

    expect(Trader::query()->count())->toBe(1)
        ->and($importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($importRun->success_count)->toBe(2)
        ->and($importRun->failure_count)->toBe(0);
});

it('treats the same in-page cid used with different usernames as a conflict for every participating entry', function (): void {
    $entryA = importRankingPageEntry(cid: 'cid-conflict-1', username: 'trader_conflict_a');
    $entryB = importRankingPageEntry(cid: 'cid-conflict-1', username: 'trader_conflict_b');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$entryA, $entryB]),
        importRankingPageQuery(),
    );

    expect(Trader::query()->count())->toBe(0)
        ->and($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->failure_count)->toBe(2)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('cid-conflict-1')
        ->and($importRun->error_summary)->not->toContain('trader_conflict_a')
        ->and($importRun->error_summary)->not->toContain('trader_conflict_b');
});

it('treats the same in-page username used with different cids as a conflict for every participating entry', function (): void {
    $entryA = importRankingPageEntry(cid: 'cid-conflict-a', username: 'trader_conflict_1');
    $entryB = importRankingPageEntry(cid: 'cid-conflict-b', username: 'trader_conflict_1');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$entryA, $entryB]),
        importRankingPageQuery(),
    );

    expect(Trader::query()->count())->toBe(0)
        ->and($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->failure_count)->toBe(2)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('cid-conflict-a')
        ->and($importRun->error_summary)->not->toContain('cid-conflict-b')
        ->and($importRun->error_summary)->not->toContain('trader_conflict_1');
});

// --- ImportRun status/count semantics --------------------------------------

it('produces a partial ImportRun when the page mixes valid and conflicting entries', function (): void {
    Trader::factory()->create(['external_cid' => '100001', 'username' => 'someone_else']);

    $validEntry = importRankingPageEntry(cid: '200002', username: 'trader_002');
    $conflictingEntry = importRankingPageEntry(cid: '100001', username: 'trader_001');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$validEntry, $conflictingEntry]),
        importRankingPageQuery(),
    );

    expect($importRun->status)->toBe(ImportRunStatus::Partial)
        ->and($importRun->success_count)->toBe(1)
        ->and($importRun->failure_count)->toBe(1);
});

it('produces a failed ImportRun when every entry is a conflict', function (): void {
    Trader::factory()->create(['external_cid' => '100001', 'username' => 'someone_else']);

    $conflictingEntry = importRankingPageEntry(cid: '100001', username: 'trader_001');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$conflictingEntry]),
        importRankingPageQuery(),
    );

    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->failure_count)->toBe(1);
});

it('produces a completed ImportRun with zero counts for an empty ranking page', function (): void {
    $importRun = (new ImportRankingPage)->handle(importRankingPagePage([]), importRankingPageQuery());

    expect($importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->failure_count)->toBe(0);
});

// --- ImportRun bookkeeping ---------------------------------------------------

it('creates an ImportRun with sanitized metadata and request_count of 1 on a successful import', function (): void {
    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([importRankingPageEntry()]),
        importRankingPageQuery(),
    );

    expect($importRun->source)->toBe('etoro')
        ->and($importRun->type)->toBe('rankings')
        ->and($importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($importRun->request_count)->toBe(1)
        ->and($importRun->success_count)->toBe(1)
        ->and($importRun->failure_count)->toBe(0)
        ->and($importRun->finished_at)->not->toBeNull()
        ->and($importRun->error_summary)->toBeNull()
        ->and($importRun->metadata)->toBe([
            'query' => [
                'period' => 'lastYear',
                'page' => 1,
                'pageSize' => 20,
                'sort' => '-copiers',
                'country' => 'US',
            ],
            'pagination' => [
                'page' => 1,
                'pageSize' => 20,
                'totalItems' => 1,
                'hasNext' => false,
            ],
            'entry_count' => 1,
        ]);
});

it('creates a new ImportRun record on every call, including a repeated import', function (): void {
    $page = importRankingPagePage([importRankingPageEntry()]);

    $first = (new ImportRankingPage)->handle($page, importRankingPageQuery());
    $second = (new ImportRankingPage)->handle($page, importRankingPageQuery());

    expect($first->id)->not->toBe($second->id)
        ->and(ImportRun::query()->count())->toBe(2);
});

// --- Unexpected persistence failure ------------------------------------------

it('rolls back all trader writes and marks the ImportRun failed when its finalization save unexpectedly fails', function (): void {
    // A temporary model event listener injects a genuine, unforced failure
    // into the ImportRun finalization save (not the initial "running"
    // create), after at least one trader write has already happened inside
    // the same transaction. The listener is registered on a *cloned* event
    // dispatcher, installed only for the duration of this test and restored
    // in `finally` — Eloquent's $dispatcher is one static slot shared by
    // every model class, so flushing it outright (rather than isolating a
    // clone) would strip listeners for every model, not just this test's,
    // for the rest of the process.
    $page = importRankingPagePage([importRankingPageEntry()]);

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
        expect(fn () => (new ImportRankingPage)->handle($page, importRankingPageQuery()))
            ->toThrow(RuntimeException::class);
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }

    expect(Trader::query()->count())->toBe(0);

    $importRun = ImportRun::query()->latest('id')->firstOrFail();

    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->failure_count)->toBe(1)
        ->and($importRun->finished_at)->not->toBeNull()
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('Simulated ImportRun finalization failure');
});

// --- Checkpoint E: optional parent_import_run_id integration ---------------

it('leaves parent_import_run_id null when no parent is passed, preserving every existing single-page/fixture caller', function (): void {
    $page = importRankingPagePage([importRankingPageEntry()]);

    $importRun = (new ImportRankingPage)->handle($page, importRankingPageQuery());

    expect($importRun->parent_import_run_id)->toBeNull();
});

it('sets parent_import_run_id on the child run when a valid running rankings_discovery aggregate is passed', function (): void {
    $aggregateRun = ImportRun::factory()->create([
        'type' => 'rankings_discovery',
        'status' => ImportRunStatus::Running,
    ]);

    $page = importRankingPagePage([importRankingPageEntry()]);

    $childRun = (new ImportRankingPage)->handle($page, importRankingPageQuery(), $aggregateRun->id);

    expect($childRun->parent_import_run_id)->toBe($aggregateRun->id)
        ->and($childRun->type)->toBe('rankings');
});

it('fails closed without any write when the parent id does not exist', function (): void {
    $page = importRankingPagePage([importRankingPageEntry()]);

    expect(fn () => (new ImportRankingPage)->handle($page, importRankingPageQuery(), 999999))
        ->toThrow(InvalidArgumentException::class);

    expect(Trader::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);
});

it('fails closed when the parent exists but is not type=rankings_discovery, refusing a fixture-only or per-page run as a parent', function (): void {
    $notAnAggregate = ImportRun::factory()->create([
        'type' => 'rankings',
        'status' => ImportRunStatus::Running,
    ]);

    $page = importRankingPagePage([importRankingPageEntry()]);

    expect(fn () => (new ImportRankingPage)->handle($page, importRankingPageQuery(), $notAnAggregate->id))
        ->toThrow(InvalidArgumentException::class);

    expect(Trader::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(1);
});

it('fails closed when the parent aggregate exists but is not status=Running', function (): void {
    $finishedAggregate = ImportRun::factory()->create([
        'type' => 'rankings_discovery',
        'status' => ImportRunStatus::Completed,
    ]);

    $page = importRankingPagePage([importRankingPageEntry()]);

    expect(fn () => (new ImportRankingPage)->handle($page, importRankingPageQuery(), $finishedAggregate->id))
        ->toThrow(InvalidArgumentException::class);

    expect(Trader::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(1);
});

it('fails closed when the parent aggregate exists but source is not etoro', function (): void {
    $wrongSource = ImportRun::factory()->create([
        'source' => 'other',
        'type' => 'rankings_discovery',
        'status' => ImportRunStatus::Running,
    ]);

    $page = importRankingPagePage([importRankingPageEntry()]);

    expect(fn () => (new ImportRankingPage)->handle($page, importRankingPageQuery(), $wrongSource->id))
        ->toThrow(InvalidArgumentException::class);
});

// --- Checkpoint F: row-level failure audit ----------------------------------

it('records an IdentityConflictWithinPage failure row with the correct 1-based row_number', function (): void {
    $entryA = importRankingPageEntry(cid: 'cid-conflict-1', username: 'trader_conflict_a');
    $entryB = importRankingPageEntry(cid: 'cid-conflict-1', username: 'trader_conflict_b');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$entryA, $entryB]),
        importRankingPageQuery(),
    );

    expect($importRun->failures)->toHaveCount(2);

    $failures = $importRun->failures()->orderBy('row_number')->get();

    expect($failures[0]->row_number)->toBe(1)
        ->and($failures[0]->reason)->toBe(ImportRunFailureReason::IdentityConflictWithinPage)
        ->and($failures[0]->external_cid)->toBe('cid-conflict-1')
        ->and($failures[0]->username)->toBe('trader_conflict_a')
        ->and($failures[1]->row_number)->toBe(2)
        ->and($failures[1]->reason)->toBe(ImportRunFailureReason::IdentityConflictWithinPage)
        ->and($failures[1]->external_cid)->toBe('cid-conflict-1')
        ->and($failures[1]->username)->toBe('trader_conflict_b');
});

it('records an IdentityConflictWithExistingTrader failure row for a conflict against a previously-imported trader', function (): void {
    Trader::factory()->create(['external_cid' => '100001', 'username' => 'someone_else']);

    $conflictingEntry = importRankingPageEntry(cid: '100001', username: 'trader_001');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$conflictingEntry]),
        importRankingPageQuery(),
    );

    $failure = $importRun->failures()->sole();

    expect($failure->row_number)->toBe(1)
        ->and($failure->reason)->toBe(ImportRunFailureReason::IdentityConflictWithExistingTrader)
        ->and($failure->external_cid)->toBe('100001')
        ->and($failure->username)->toBe('trader_001');
});

it('preserves original 1-based row order across a mix of successful and rejected entries', function (): void {
    Trader::factory()->create(['external_cid' => 'existing-cid', 'username' => 'someone_else']);

    $validEntry = importRankingPageEntry(cid: '200002', username: 'trader_002');
    $conflictingEntry = importRankingPageEntry(cid: 'existing-cid', username: 'trader_001');
    $anotherValidEntry = importRankingPageEntry(cid: '200003', username: 'trader_003');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$validEntry, $conflictingEntry, $anotherValidEntry]),
        importRankingPageQuery(),
    );

    $failure = $importRun->failures()->sole();

    expect($failure->row_number)->toBe(2);
});

it('does not create a failure row for a consistent in-page duplicate', function (): void {
    $entry = importRankingPageEntry();

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$entry, $entry]),
        importRankingPageQuery(),
    );

    expect($importRun->status)->toBe(ImportRunStatus::Completed)
        ->and($importRun->failure_count)->toBe(0)
        ->and($importRun->failures()->count())->toBe(0);
});

it('creates separate audit rows per repeated import run, without duplicate traders', function (): void {
    Trader::factory()->create(['external_cid' => 'existing-cid', 'username' => 'someone_else']);
    $conflictingEntry = importRankingPageEntry(cid: 'existing-cid', username: 'trader_001');
    $page = importRankingPagePage([$conflictingEntry]);

    $firstRun = (new ImportRankingPage)->handle($page, importRankingPageQuery());
    $secondRun = (new ImportRankingPage)->handle($page, importRankingPageQuery());

    expect($firstRun->id)->not->toBe($secondRun->id)
        ->and($firstRun->failures()->count())->toBe(1)
        ->and($secondRun->failures()->count())->toBe(1)
        ->and(ImportRunFailure::query()->count())->toBe(2)
        ->and(Trader::query()->count())->toBe(1);
});

it('rolls back BOTH the new trader write AND the attempted row-failure record together, in the same transaction, on an unexpected persistence failure — mixed page (one success, one conflict)', function (): void {
    $originalDispatcher = ImportRun::getEventDispatcher();
    ImportRun::setEventDispatcher(clone $originalDispatcher);

    $hasThrown = false;

    ImportRun::saving(function (ImportRun $importRun) use (&$hasThrown): void {
        if (! $hasThrown && $importRun->status !== ImportRunStatus::Running) {
            $hasThrown = true;

            throw new RuntimeException('Simulated ImportRun finalization failure for test purposes.');
        }
    });

    $preExistingTrader = Trader::factory()->create([
        'external_cid' => 'existing-cid',
        'username' => 'someone_else',
        'copiers_count' => 111,
    ]);

    // One entry that would succeed as a brand-new trader, and one that
    // conflicts with the pre-existing row above — a single page exercising
    // both rollback paths (a new Trader::create() AND an attempted
    // ImportRunFailure::create()) at once, in one transaction.
    $newValidEntry = importRankingPageEntry(cid: 'new-cid', username: 'brand_new_trader');
    $conflictingEntry = importRankingPageEntry(cid: 'existing-cid', username: 'trader_001');
    $page = importRankingPagePage([$newValidEntry, $conflictingEntry]);

    try {
        expect(fn () => (new ImportRankingPage)->handle($page, importRankingPageQuery()))
            ->toThrow(RuntimeException::class);
    } finally {
        ImportRun::setEventDispatcher($originalDispatcher);
    }

    // The new trader write never committed.
    expect(Trader::query()->where('external_cid', 'new-cid')->exists())->toBeFalse();
    expect(Trader::query()->where('username', 'brand_new_trader')->exists())->toBeFalse();

    // The pre-existing conflicting trader is completely untouched.
    expect($preExistingTrader->refresh()->username)->toBe('someone_else')
        ->and($preExistingTrader->copiers_count)->toBe(111);

    // Only the one pre-existing trader exists — nothing else committed.
    expect(Trader::query()->count())->toBe(1);

    $importRun = ImportRun::query()->latest('id')->firstOrFail();

    // The blanket-failure recovery path sets failure_count to the full
    // entry count (both entries, including the one that would otherwise
    // have succeeded) without ever having successfully committed (or
    // fabricating) a row-level reason for either — the transaction rolled
    // back any ImportRunFailure insert attempted during processing, the
    // same way it rolled back the new trader write.
    expect($importRun->status)->toBe(ImportRunStatus::Failed)
        ->and($importRun->failure_count)->toBe(2)
        ->and($importRun->success_count)->toBe(0)
        ->and($importRun->failures()->count())->toBe(0);
});

it('keeps error_summary sanitized (count-only) even when row-level failure records exist with real identity values', function (): void {
    Trader::factory()->create(['external_cid' => 'existing-cid', 'username' => 'someone_else']);
    $conflictingEntry = importRankingPageEntry(cid: 'existing-cid', username: 'trader_001');

    $importRun = (new ImportRankingPage)->handle(
        importRankingPagePage([$conflictingEntry]),
        importRankingPageQuery(),
    );

    expect($importRun->failures()->count())->toBe(1)
        ->and($importRun->error_summary)->not->toBeNull()
        ->and($importRun->error_summary)->not->toContain('existing-cid')
        ->and($importRun->error_summary)->not->toContain('trader_001');
});
