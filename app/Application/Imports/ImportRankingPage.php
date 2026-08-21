<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Etoro\Data\RankingEntry;
use App\Etoro\Data\RankingPage;
use App\Etoro\RankingQuery;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\Trader;
use App\Models\TraderStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Persists an already-mapped eToro RankingPage as an idempotent trader
 * upsert, recorded as a single ImportRun. Does not call EtoroClient, does
 * not map raw payloads, and does not read fixtures — the caller is
 * responsible for producing the RankingPage.
 */
final class ImportRankingPage
{
    private const SOURCE = 'etoro';

    private const TYPE = 'rankings';

    /**
     * The only ImportRun `type` a `parentImportRunId` may reference — the
     * live multi-page discovery aggregate run (see
     * App\Application\Imports\DiscoverEtoroTraders). A fixture-only or
     * per-page `rankings` run can never pass this check, so it can never
     * masquerade as a parent.
     */
    private const AGGREGATE_TYPE = 'rankings_discovery';

    /**
     * $parentImportRunId is optional and defaults to null so every existing
     * caller (ImportRankingPageFromFixture, and any direct single-page
     * caller) is completely unaffected. When provided, it must reference an
     * existing etoro/rankings_discovery aggregate ImportRun with status
     * Running — checked BEFORE any write, fail-closed, so a fixture-only or
     * already-finished run can never be used as a parent.
     */
    public function handle(RankingPage $rankingPage, RankingQuery $rankingQuery, ?int $parentImportRunId = null): ImportRun
    {
        if ($parentImportRunId !== null) {
            $this->assertValidParent($parentImportRunId);
        }

        $importedAt = now();

        $importRun = ImportRun::create([
            'parent_import_run_id' => $parentImportRunId,
            'source' => self::SOURCE,
            'type' => self::TYPE,
            'status' => ImportRunStatus::Running,
            'metadata' => $this->buildMetadata($rankingQuery, $rankingPage),
            'request_count' => 1,
            'success_count' => 0,
            'failure_count' => 0,
            'started_at' => $importedAt,
            'finished_at' => null,
        ]);

        try {
            // Entry resolution (a DB read) and the finalizing ImportRun save
            // both live inside this one transaction, alongside the trader
            // writes — if the finalization save itself fails, every trader
            // write for this page must roll back too, not just persist
            // half-done.
            DB::transaction(function () use ($rankingPage, $importedAt, $importRun): void {
                [$successCount, $failureCount] = $this->processEntries($rankingPage->entries, $importedAt);

                $importRun->forceFill([
                    'status' => $this->determineStatus($successCount, $failureCount),
                    'success_count' => $successCount,
                    'failure_count' => $failureCount,
                    'finished_at' => now(),
                    'error_summary' => $this->buildConflictSummary($failureCount),
                ])->save();
            });
        } catch (Throwable $exception) {
            // The transaction rolled back everything attempted for this
            // page — trader writes and the finalization save above never
            // committed — so nothing succeeded, including entries that were
            // merely controlled identity conflicts.
            $importRun->forceFill([
                'status' => ImportRunStatus::Failed,
                'success_count' => 0,
                'failure_count' => count($rankingPage->entries),
                'finished_at' => now(),
                'error_summary' => 'Unexpected persistence failure while importing the ranking page.',
            ])->save();

            throw $exception;
        }

        return $importRun->refresh();
    }

    /**
     * Fail-closed guard: a parent must be a real, currently-Running
     * etoro/rankings_discovery aggregate ImportRun — never a fixture-only
     * run, a per-page `rankings` run, a finished run, or a nonexistent id.
     * Runs before any write for this page.
     */
    private function assertValidParent(int $parentImportRunId): void
    {
        $parent = ImportRun::query()->find($parentImportRunId);

        if (
            $parent === null
            || $parent->source !== self::SOURCE
            || $parent->type !== self::AGGREGATE_TYPE
            || $parent->status !== ImportRunStatus::Running
        ) {
            throw new InvalidArgumentException(
                'parentImportRunId must reference an existing etoro/rankings_discovery aggregate ImportRun with status Running.'
            );
        }
    }

    /**
     * Processes entries strictly in RankingPage order, one at a time.
     * In-page ambiguity is resolved first, for the whole page, before any
     * write (see resolveInPageIdentityGroups()). Identity against
     * previously-imported traders is then resolved per entry via real, live
     * queries against the traders table (not an in-memory PHP map built
     * from an earlier bulk fetch) so that resolution uses exactly the same
     * equality semantics as the table's own unique indexes — including
     * whatever collation MySQL applies to external_cid/username. Because a
     * write for one entry is visible to every later entry's resolution
     * query within this same transaction, an entry that turns out to match
     * a row written earlier in this same page (rather than a pre-existing
     * row) is still resolved safely as a conflict — no exception, no
     * corrupted row.
     *
     * @param  list<RankingEntry>  $entries
     * @return array{0: int, 1: int} success count, failure count
     */
    private function processEntries(array $entries, CarbonInterface $importedAt): array
    {
        [$conflictIndexes, $lastIndexByEntryIndex] = $this->resolveInPageIdentityGroups($entries);

        $successCount = 0;
        $failureCount = 0;

        foreach ($entries as $index => $entry) {
            if (isset($conflictIndexes[$index])) {
                $failureCount++;

                continue;
            }

            $resolution = $this->resolveAgainstExistingTrader($entry);

            if ($resolution['conflict']) {
                $failureCount++;

                continue;
            }

            if ($lastIndexByEntryIndex[$index] === $index) {
                $this->upsertTrader($entry, $resolution['existing'], $importedAt);
            }

            $successCount++;
        }

        return [$successCount, $failureCount];
    }

    /**
     * Determines, for the whole page and before any write, which entries
     * are a fail-closed in-page identity conflict and which are a
     * consistent duplicate (grouped by "last occurrence" list position).
     * Two entries are the same identity if the *database* — not a PHP
     * string comparison — considers their external_cid values equal, or
     * their username values equal; every entry sharing either equal value
     * with another entry that disagrees on the other field is a conflict
     * for all participants, never just some of them.
     *
     * On a driver that exposes a queryable column collation (mysql,
     * mariadb), this is resolved with two small, parameterized,
     * self-documenting SQL comparisons against the traders table's own
     * external_cid/username column collations (never a PHP
     * strtolower()/Unicode-canonicalization guess) — see
     * resolveInPageIdentityGroupsUsingDatabaseCollation(). On sqlite —
     * the only other driver this PHP-exact equivalence is currently
     * verified for — string comparison is already byte-exact, so a plain
     * PHP-side grouping is equivalent and avoids an unnecessary round trip
     * — see resolveInPageIdentityGroupsUsingPhpExactEquality().
     *
     * @param  list<RankingEntry>  $entries
     * @return array{0: array<int, true>, 1: array<int, int>} conflicting entry indexes; last occurrence index per entry index
     */
    private function resolveInPageIdentityGroups(array $entries): array
    {
        if ($entries === []) {
            return [[], []];
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $cidCollation = $this->columnCollation('external_cid');
            $usernameCollation = $this->columnCollation('username');

            if ($cidCollation === null || $usernameCollation === null) {
                // Fail closed: silently falling back to PHP-exact equality
                // here would reintroduce exactly the equality mismatch this
                // method exists to close. This is caught by handle()'s
                // outer catch, which rolls the transaction back, marks the
                // ImportRun failed, and stores only the sanitized generic
                // summary — never this message or any entry value.
                throw new RuntimeException(
                    'Unable to resolve the traders table external_cid/username column collation required for safe in-page identity comparison.'
                );
            }

            return $this->resolveInPageIdentityGroupsUsingDatabaseCollation($entries, $cidCollation, $usernameCollation);
        }

        if ($driver === 'sqlite') {
            // SQLite's default text comparison is byte-exact, which this
            // fallback already assumes — the only driver this equivalence
            // is currently verified for.
            return $this->resolveInPageIdentityGroupsUsingPhpExactEquality($entries);
        }

        throw new RuntimeException(
            "In-page identity resolution has no verified equality contract for the '{$driver}' database driver."
        );
    }

    /**
     * Looks up the *actual* collation MySQL/MariaDB applies to a traders
     * column, from information_schema — never assumed or hard-coded — so
     * the in-page comparison below is provably the same equality contract
     * the column's own unique index enforces, not a guess at what that
     * contract is. Uses the connection's actual physical (prefixed) table
     * name — information_schema is not aware of Laravel's table-prefix
     * abstraction, so the logical model table name alone would silently
     * fail to match on a prefixed connection. The collation name is
     * validated against a strict allow-list pattern before ever being
     * interpolated into SQL (a collation name is a MySQL identifier, not a
     * bindable value).
     */
    private function columnCollation(string $column): ?string
    {
        $connection = DB::connection();
        $physicalTable = $connection->getTablePrefix().(new Trader)->getTable();

        $row = $connection->selectOne(
            'select COLLATION_NAME as collation_name from information_schema.COLUMNS '.
            'where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$physicalTable, $column],
        );

        $collation = is_object($row) ? ((array) $row)['collation_name'] ?? null : null;

        if (! is_string($collation) || $collation === '' || preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
            return null;
        }

        return $collation;
    }

    /**
     * Two self-joins of a literal, parameter-bound derived table
     * representing this page's entries — one grouping by external_cid
     * equality (under its real collation) to find entries whose equal cid
     * disagrees on username, one grouping by username equality (under its
     * real collation) to find entries whose equal username disagrees on
     * cid. This is the "minimal, documented, parameterized SQL comparison"
     * this method exists for: no temporary table, no schema change, no
     * PHP-side canonicalization — only the two literal values from each
     * entry, compared using the database's own collation.
     *
     * @param  list<RankingEntry>  $entries
     * @return array{0: array<int, true>, 1: array<int, int>}
     */
    private function resolveInPageIdentityGroupsUsingDatabaseCollation(array $entries, string $cidCollation, string $usernameCollation): array
    {
        [$derivedTableSql, $entryBindings] = $this->buildEntriesDerivedTableSql($entries);

        $cidGroupSql = "
            select a.entry_index as entry_index,
                   max(case when b.username collate `{$usernameCollation}` <> a.username collate `{$usernameCollation}` then 1 else 0 end) as ambiguous,
                   max(b.entry_index) as last_index
            from ({$derivedTableSql}) as a
            inner join ({$derivedTableSql}) as b
                on a.cid collate `{$cidCollation}` = b.cid collate `{$cidCollation}`
            group by a.entry_index
        ";

        $usernameGroupSql = "
            select a.entry_index as entry_index,
                   max(case when b.cid collate `{$cidCollation}` <> a.cid collate `{$cidCollation}` then 1 else 0 end) as ambiguous
            from ({$derivedTableSql}) as a
            inner join ({$derivedTableSql}) as b
                on a.username collate `{$usernameCollation}` = b.username collate `{$usernameCollation}`
            group by a.entry_index
        ";

        $cidGroupRows = DB::select($cidGroupSql, array_merge($entryBindings, $entryBindings));
        $usernameGroupRows = DB::select($usernameGroupSql, array_merge($entryBindings, $entryBindings));

        $conflictIndexes = [];
        $lastIndexByEntryIndex = [];

        foreach ($cidGroupRows as $row) {
            $row = (array) $row;
            $index = (int) $row['entry_index'];

            if ((int) $row['ambiguous'] === 1) {
                $conflictIndexes[$index] = true;
            }

            $lastIndexByEntryIndex[$index] = (int) $row['last_index'];
        }

        foreach ($usernameGroupRows as $row) {
            $row = (array) $row;

            if ((int) $row['ambiguous'] === 1) {
                $conflictIndexes[(int) $row['entry_index']] = true;
            }
        }

        return [$conflictIndexes, $lastIndexByEntryIndex];
    }

    /**
     * @param  list<RankingEntry>  $entries
     * @return array{0: string, 1: list<int|string>}
     */
    private function buildEntriesDerivedTableSql(array $entries): array
    {
        $selects = [];
        $bindings = [];

        foreach ($entries as $index => $entry) {
            $selects[] = 'select ? as entry_index, ? as cid, ? as username';
            $bindings[] = $index;
            $bindings[] = $entry->cid;
            $bindings[] = $entry->username;
        }

        return [implode(' union all ', $selects), $bindings];
    }

    /**
     * Byte-exact PHP-side equivalent of the collation-aware resolution
     * above, used on drivers (sqlite in this test suite) whose default
     * string comparison is already byte-exact, so it agrees with this
     * comparison by construction. Entries sharing the same external_cid AND
     * the same username (however many times they repeat, even as the exact
     * same RankingEntry instance) are a valid, consistent duplicate — not a
     * conflict — collapsed into a single trader write using the last
     * occurrence's data (RankingPage preserves server order). "Last" is
     * determined by list position, never by object identity, since the
     * same instance can legally appear at more than one position. Entries
     * that share only one of the two identity fields, with disagreeing
     * values for the other, are a fail-closed in-page identity conflict for
     * every entry participating in that ambiguity.
     *
     * @param  list<RankingEntry>  $entries
     * @return array{0: array<int, true>, 1: array<int, int>}
     */
    private function resolveInPageIdentityGroupsUsingPhpExactEquality(array $entries): array
    {
        $usernamesByCid = [];
        $cidsByUsername = [];

        foreach ($entries as $entry) {
            $usernamesByCid[$entry->cid][$entry->username] = true;
            $cidsByUsername[$entry->username][$entry->cid] = true;
        }

        $inconsistentCids = [];

        foreach ($usernamesByCid as $cid => $usernames) {
            if (count($usernames) > 1) {
                $inconsistentCids[$cid] = true;
            }
        }

        $inconsistentUsernames = [];

        foreach ($cidsByUsername as $username => $cids) {
            if (count($cids) > 1) {
                $inconsistentUsernames[$username] = true;
            }
        }

        $conflictIndexes = [];
        $lastIndexByCid = [];

        foreach ($entries as $index => $entry) {
            if (isset($inconsistentCids[$entry->cid]) || isset($inconsistentUsernames[$entry->username])) {
                $conflictIndexes[$index] = true;

                continue;
            }

            $lastIndexByCid[$entry->cid] = $index;
        }

        $lastIndexByEntryIndex = [];

        foreach ($entries as $index => $entry) {
            if (! isset($conflictIndexes[$index])) {
                $lastIndexByEntryIndex[$index] = $lastIndexByCid[$entry->cid];
            }
        }

        return [$conflictIndexes, $lastIndexByEntryIndex];
    }

    /**
     * Resolves identity via live queries against the real columns, so that
     * "does this cid/username already belong to a row" uses precisely the
     * same comparison MySQL's own unique indexes use — deliberately not a
     * PHP-side canonicalization (lowercasing, etc.) of the compared values.
     *
     * @return array{conflict: bool, existing: ?Trader}
     */
    private function resolveAgainstExistingTrader(RankingEntry $entry): array
    {
        $matchByCid = Trader::query()->where('external_cid', $entry->cid)->first();
        $matchByUsername = Trader::query()->where('username', $entry->username)->first();

        $isConflict = match (true) {
            $matchByCid === null && $matchByUsername === null => false,
            $matchByCid !== null && $matchByUsername !== null && $matchByCid->is($matchByUsername) => false,
            default => true,
        };

        if ($isConflict) {
            return ['conflict' => true, 'existing' => null];
        }

        return ['conflict' => false, 'existing' => $matchByCid];
    }

    private function upsertTrader(RankingEntry $entry, ?Trader $existing, CarbonInterface $importedAt): void
    {
        if ($existing !== null) {
            $existing->forceFill([
                'ranking_type' => $entry->type,
                'ranking_sub_type' => $entry->subType,
                'copiers_count' => $entry->copiers,
                'last_seen_at' => $importedAt,
            ])->save();

            return;
        }

        Trader::create([
            'external_cid' => $entry->cid,
            'username' => $entry->username,
            'ranking_type' => $entry->type,
            'ranking_sub_type' => $entry->subType,
            'copiers_count' => $entry->copiers,
            'status' => TraderStatus::Candidate,
            'first_seen_at' => $importedAt,
            'last_seen_at' => $importedAt,
        ]);
    }

    private function determineStatus(int $successCount, int $failureCount): ImportRunStatus
    {
        if ($failureCount === 0) {
            return ImportRunStatus::Completed;
        }

        if ($successCount > 0) {
            return ImportRunStatus::Partial;
        }

        return ImportRunStatus::Failed;
    }

    /**
     * Sanitized, count-only summary — never a CID, username, raw payload, or
     * exception detail.
     */
    private function buildConflictSummary(int $failureCount): ?string
    {
        if ($failureCount === 0) {
            return null;
        }

        return sprintf(
            '%d ranking %s rejected due to a controlled trader identity conflict.',
            $failureCount,
            $failureCount === 1 ? 'entry was' : 'entries were',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(RankingQuery $rankingQuery, RankingPage $rankingPage): array
    {
        return [
            'query' => [
                'period' => $rankingQuery->period,
                'page' => $rankingQuery->page,
                'pageSize' => $rankingQuery->pageSize,
                'sort' => $rankingQuery->sort,
                'country' => $rankingQuery->country,
            ],
            'pagination' => [
                'page' => $rankingPage->pagination->page,
                'pageSize' => $rankingPage->pagination->pageSize,
                'totalItems' => $rankingPage->pagination->totalItems,
                'hasNext' => $rankingPage->pagination->hasNext,
            ],
            'entry_count' => count($rankingPage->entries),
        ];
    }
}
