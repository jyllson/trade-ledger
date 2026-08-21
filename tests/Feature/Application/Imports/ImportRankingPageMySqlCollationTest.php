<?php

use App\Application\Imports\ImportRankingPage;
use App\Etoro\Data\RankingEntry;
use App\Etoro\Data\RankingPage;
use App\Etoro\Data\RankingPagination;
use App\Etoro\RankingQuery;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Focused MySQL 8.4 regression coverage for the traders table's actual
 * collation contract: `external_cid`/`username` carry no explicit
 * column-level COLLATE override, so they inherit the mysql connection's
 * default collation, which config/database.php sets to
 * `env('DB_COLLATION', 'utf8mb4_unicode_ci')` — case-insensitive. On
 * SQLite (the default `testing` connection used everywhere else in this
 * suite; see phpunit.xml), string comparison is byte-exact, so this class
 * of bug cannot be observed there — it requires a real MySQL connection.
 *
 * This file opens a SEPARATE, dedicated `mysql_collation_test` connection,
 * driven entirely by MYSQL_COLLATION_TEST_* environment variables — never
 * the app's `mysql` connection (config/database.php, the local
 * `trade_ledger` development database per docs/DECISIONS.md D-001/D-002)
 * and never the default sqlite `testing` connection. It is skipped unless
 * that dedicated environment is explicitly configured.
 *
 * Safety is by construction, not by naming convention: every table this
 * connection touches gets a random, per-test table prefix
 * (`irp_<random>_`), so migrate/query/drop operations can never reach an
 * unprefixed `traders`/`import_runs`/`migrations` table no matter which
 * database name MYSQL_COLLATION_TEST_DATABASE happens to resolve to. The
 * database-name check kept below is only a secondary, defense-in-depth
 * guard on top of that — not the primary protection.
 */
function mysqlCollationTestConfigured(): bool
{
    $host = getenv('MYSQL_COLLATION_TEST_HOST');
    $database = getenv('MYSQL_COLLATION_TEST_DATABASE');

    return $host !== false && $host !== '' && $database !== false && $database !== '';
}

function importRankingPageEntryForMySqlCollationTest(
    string $cid,
    string $username,
    string $type = 'trader',
    string $subType = 'pi-certified',
    int $copiers = 5000,
): RankingEntry {
    return new RankingEntry(cid: $cid, username: $username, type: $type, subType: $subType, copiers: $copiers);
}

function importRankingPageQueryForMySqlCollationTest(): RankingQuery
{
    return new RankingQuery(period: 'lastYear', page: 1, pageSize: 20);
}

/**
 * @param  list<RankingEntry>  $entries
 */
function importRankingPageForMySqlCollationTest(array $entries): RankingPage
{
    return new RankingPage(
        entries: $entries,
        pagination: new RankingPagination(page: 1, pageSize: 20, totalItems: count($entries), hasNext: false),
    );
}

/**
 * Runs $callback against a freshly migrated, randomly prefixed, dedicated
 * MySQL connection, and unconditionally tears the connection back down
 * afterward — even if migration or the callback itself fails partway
 * through. Skips (rather than running) when no dedicated environment is
 * configured. Never touches config/env beyond this process's in-memory
 * config repository, and never leaves `database.default` or the
 * `mysql_collation_test` connection changed for any later test.
 */
function withIsolatedMySqlCollationConnection(Closure $callback): void
{
    if (! mysqlCollationTestConfigured()) {
        test()->markTestSkipped(
            'Skipped: MYSQL_COLLATION_TEST_HOST / MYSQL_COLLATION_TEST_DATABASE are not set. '
            .'This file only runs against a dedicated MySQL 8.4 test database — never the app\'s own '
            .'mysql connection or the sqlite testing connection. See docs/DECISIONS.md for the '
            .'utf8mb4_unicode_ci collation regression it guards.'
        );

        return;
    }

    $connectionName = 'mysql_collation_test';
    $database = (string) getenv('MYSQL_COLLATION_TEST_DATABASE');

    // Secondary, defense-in-depth guard only — real isolation comes from
    // the random table prefix configured below, which is safe regardless
    // of which database name this connection points at.
    $protectedDatabaseNames = array_filter([
        'trade_ledger',
        (string) config('database.connections.mysql.database'),
    ]);

    if (in_array($database, $protectedDatabaseNames, true)) {
        throw new RuntimeException(
            "Refusing to run: MYSQL_COLLATION_TEST_DATABASE ('{$database}') resolves to a protected database name."
        );
    }

    // Captured before any mutation below, so cleanup can restore the whole
    // `database.connections` array verbatim — including the total absence
    // of a `mysql_collation_test` key if it wasn't configured before this
    // call. Illuminate\Config\Repository (Laravel 13) has no forget()
    // method, and its ArrayAccess::offsetUnset() only nulls a key rather
    // than removing it, so a targeted "unset" of the child key cannot
    // recreate an originally-absent key's absence — only replacing the
    // parent array wholesale can.
    $originalDefaultConnection = config('database.default');
    $originalConnections = (array) config('database.connections');
    $prefix = 'irp_'.bin2hex(random_bytes(4)).'_';

    // Every mutation (purge, connection config, migration, callback) lives
    // inside this one guarded block, so a failure at any point — including
    // a setup step that never finishes configuring the isolated connection
    // — still reaches the independent cleanup steps below instead of
    // leaking global state.
    $primaryException = null;
    $isolatedConnectionConfigured = false;

    try {
        DB::purge($connectionName);

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'mysql',
                'host' => getenv('MYSQL_COLLATION_TEST_HOST'),
                'port' => getenv('MYSQL_COLLATION_TEST_PORT') ?: '3306',
                'database' => $database,
                'username' => getenv('MYSQL_COLLATION_TEST_USERNAME') ?: 'root',
                'password' => getenv('MYSQL_COLLATION_TEST_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => $prefix,
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],
            'database.default' => $connectionName,
        ]);

        // Only from this point on does `$connectionName` point at a
        // connection this call itself installed, with its own freshly
        // generated random prefix — the signal the cleanup below relies on
        // before ever attempting a drop.
        $isolatedConnectionConfigured = true;

        Artisan::call('migrate', [
            '--database' => $connectionName,
            '--path' => [
                'database/migrations/2026_08_10_080517_create_traders_table.php',
                'database/migrations/2026_08_10_080518_create_import_runs_table.php',
                'database/migrations/2026_08_21_110117_add_parent_import_run_id_and_type_index_to_import_runs_table.php',
                'database/migrations/2026_08_21_112838_create_import_run_failures_table.php',
            ],
            '--realpath' => false,
            '--force' => true,
        ]);

        $callback();
    } catch (Throwable $exception) {
        $primaryException = $exception;
    }

    // Each cleanup step below is attempted independently — a failure in
    // one (e.g. the connection never came up, so purge or drop fails) must
    // not skip the others, especially restoring `database.connections` and
    // `database.default` for later tests. The first cleanup failure is
    // remembered but never allowed to outrank $primaryException.
    $cleanupException = null;

    if ($isolatedConnectionConfigured) {
        try {
            // dropIfExists() applies the connection's own configured prefix
            // automatically, so this only ever drops irp_<random>_traders
            // etc. — never a bare `traders`/`import_runs`/`migrations`
            // table. Gated on $isolatedConnectionConfigured so this never
            // runs against a pre-existing or partially-configured
            // `mysql_collation_test` connection that this call didn't set
            // up itself.
            // import_run_failures has a foreign key onto import_runs, so it
            // is dropped first, ahead of every other domain table.
            Schema::connection($connectionName)->dropIfExists('import_run_failures');
            Schema::connection($connectionName)->dropIfExists('traders');
            Schema::connection($connectionName)->dropIfExists('import_runs');
            Schema::connection($connectionName)->dropIfExists('migrations');
        } catch (Throwable $exception) {
            $cleanupException ??= $exception;
        }
    }

    try {
        DB::purge($connectionName);
    } catch (Throwable $exception) {
        $cleanupException ??= $exception;
    }

    try {
        config(['database.connections' => $originalConnections]);
    } catch (Throwable $exception) {
        $cleanupException ??= $exception;
    }

    try {
        config(['database.default' => $originalDefaultConnection]);
    } catch (Throwable $exception) {
        $cleanupException ??= $exception;
    }

    if ($primaryException !== null) {
        throw $primaryException;
    }

    if ($cleanupException !== null) {
        throw $cleanupException;
    }
}

it('treats an existing username and a case-variant incoming username with a different cid as a controlled conflict, not a query exception', function (): void {
    $originalDefault = config('database.default');
    $originalConnections = config('database.connections');

    withIsolatedMySqlCollationConnection(function (): void {
        Trader::factory()->create([
            'external_cid' => 'existing-cid',
            'username' => 'TraderOne',
            'copiers_count' => 111,
        ]);

        $conflictingEntry = importRankingPageEntryForMySqlCollationTest(cid: 'new-cid', username: 'traderone');
        $page = importRankingPageForMySqlCollationTest([$conflictingEntry]);

        $importRun = (new ImportRankingPage)->handle($page, importRankingPageQueryForMySqlCollationTest());

        $existing = Trader::query()->where('external_cid', 'existing-cid')->firstOrFail();

        expect($existing->username)->toBe('TraderOne')
            ->and($existing->copiers_count)->toBe(111)
            ->and(Trader::query()->count())->toBe(1)
            ->and($importRun->status)->toBe(ImportRunStatus::Failed)
            ->and($importRun->success_count)->toBe(0)
            ->and($importRun->failure_count)->toBe(1)
            ->and($importRun->error_summary)->not->toBeNull()
            ->and($importRun->error_summary)->not->toContain('existing-cid')
            ->and($importRun->error_summary)->not->toContain('new-cid')
            ->and($importRun->error_summary)->not->toContain('TraderOne')
            ->and($importRun->error_summary)->not->toContain('traderone');
    });

    expect(config('database.default'))->toBe($originalDefault)
        ->and(config('database.connections'))->toBe($originalConnections);
});

it('treats two new in-page entries with different cids and MySQL-collation-equal usernames as controlled conflicts for both, with no trader created', function (): void {
    withIsolatedMySqlCollationConnection(function (): void {
        $entryA = importRankingPageEntryForMySqlCollationTest(cid: 'cid-collation-a', username: 'CaseVariant');
        $entryB = importRankingPageEntryForMySqlCollationTest(cid: 'cid-collation-b', username: 'casevariant');
        $page = importRankingPageForMySqlCollationTest([$entryA, $entryB]);

        $importRun = (new ImportRankingPage)->handle($page, importRankingPageQueryForMySqlCollationTest());

        expect(Trader::query()->count())->toBe(0)
            ->and($importRun->status)->toBe(ImportRunStatus::Failed)
            ->and($importRun->success_count)->toBe(0)
            ->and($importRun->failure_count)->toBe(2)
            ->and($importRun->error_summary)->not->toBeNull()
            ->and($importRun->error_summary)->not->toContain('cid-collation-a')
            ->and($importRun->error_summary)->not->toContain('cid-collation-b')
            ->and($importRun->error_summary)->not->toContain('CaseVariant')
            ->and($importRun->error_summary)->not->toContain('casevariant');
    });
});

it('treats two new in-page entries with MySQL-collation-equal cids and different usernames as controlled conflicts for both, with no trader created', function (): void {
    withIsolatedMySqlCollationConnection(function (): void {
        $entryA = importRankingPageEntryForMySqlCollationTest(cid: 'CaseVariantCid', username: 'trader_a');
        $entryB = importRankingPageEntryForMySqlCollationTest(cid: 'casevariantcid', username: 'trader_b');
        $page = importRankingPageForMySqlCollationTest([$entryA, $entryB]);

        $importRun = (new ImportRankingPage)->handle($page, importRankingPageQueryForMySqlCollationTest());

        expect(Trader::query()->count())->toBe(0)
            ->and($importRun->status)->toBe(ImportRunStatus::Failed)
            ->and($importRun->success_count)->toBe(0)
            ->and($importRun->failure_count)->toBe(2)
            ->and($importRun->error_summary)->not->toBeNull()
            ->and($importRun->error_summary)->not->toContain('CaseVariantCid')
            ->and($importRun->error_summary)->not->toContain('casevariantcid')
            ->and($importRun->error_summary)->not->toContain('trader_a')
            ->and($importRun->error_summary)->not->toContain('trader_b');
    });
});

it('collapses two new in-page entries whose cid and username are both MySQL-collation-equal into a single trader, using the last entry\'s data', function (): void {
    withIsolatedMySqlCollationConnection(function (): void {
        $firstOccurrence = importRankingPageEntryForMySqlCollationTest(
            cid: 'CaseVariantCid',
            username: 'CaseVariantUser',
            copiers: 100,
            subType: 'pi-certified',
        );
        $secondOccurrence = importRankingPageEntryForMySqlCollationTest(
            cid: 'casevariantcid',
            username: 'casevariantuser',
            copiers: 200,
            subType: 'pi-elite',
        );
        $page = importRankingPageForMySqlCollationTest([$firstOccurrence, $secondOccurrence]);

        $importRun = (new ImportRankingPage)->handle($page, importRankingPageQueryForMySqlCollationTest());

        expect(Trader::query()->count())->toBe(1)
            ->and($importRun->status)->toBe(ImportRunStatus::Completed)
            ->and($importRun->success_count)->toBe(2)
            ->and($importRun->failure_count)->toBe(0)
            ->and($importRun->error_summary)->toBeNull();

        $trader = Trader::query()->first();

        expect($trader->external_cid)->toBe('casevariantcid')
            ->and($trader->username)->toBe('casevariantuser')
            ->and($trader->copiers_count)->toBe(200)
            ->and($trader->ranking_sub_type)->toBe('pi-elite')
            ->and($trader->status)->toBe(TraderStatus::Candidate);
    });
});
