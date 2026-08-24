<?php

use App\Models\ImportRun;
use App\Models\ImportRunFailure;
use App\Models\ImportRunFailureReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates an import_run_failures table with exactly the expected columns', function (): void {
    expect(Schema::hasTable('import_run_failures'))->toBeTrue();

    expect(Schema::hasColumns('import_run_failures', [
        'id',
        'import_run_id',
        'row_number',
        'external_cid',
        'username',
        'reason',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('casts row_number and import_run_id to integers', function (): void {
    $failure = ImportRunFailure::factory()->create(['row_number' => 3]);

    $fromDatabase = ImportRunFailure::query()->findOrFail($failure->id);

    expect($fromDatabase->row_number)->toBe(3)->toBeInt()
        ->and($fromDatabase->import_run_id)->toBeInt();
});

it('casts reason to the ImportRunFailureReason backed enum', function (): void {
    $failure = ImportRunFailure::factory()->create(['reason' => ImportRunFailureReason::IdentityConflictWithExistingTrader]);

    $fromDatabase = ImportRunFailure::query()->findOrFail($failure->id);

    expect($fromDatabase->reason)->toBeInstanceOf(ImportRunFailureReason::class)
        ->and($fromDatabase->reason)->toBe(ImportRunFailureReason::IdentityConflictWithExistingTrader);

    expect(DB::table('import_run_failures')->where('id', $failure->id)->value('reason'))
        ->toBe('identity_conflict_with_existing_trader');
});

it('exposes an explicit importRun BelongsTo relation', function (): void {
    $importRun = ImportRun::factory()->create();
    $failure = ImportRunFailure::factory()->create(['import_run_id' => $importRun->id]);

    expect($failure->importRun)->not->toBeNull()
        ->and($failure->importRun->id)->toBe($importRun->id);
});

it('exposes an explicit failures HasMany relation on ImportRun', function (): void {
    $importRun = ImportRun::factory()->create();
    $failureA = ImportRunFailure::factory()->create(['import_run_id' => $importRun->id, 'row_number' => 1]);
    $failureB = ImportRunFailure::factory()->create(['import_run_id' => $importRun->id, 'row_number' => 2]);
    ImportRunFailure::factory()->create(); // belongs to an unrelated run

    $ids = $importRun->failures()->pluck('id')->sort()->values();

    expect($ids->all())->toBe(collect([$failureA->id, $failureB->id])->sort()->values()->all());
});

it('enforces a foreign key constraint pointing back at import_runs.id', function (): void {
    expect(fn () => DB::table('import_run_failures')->insert([
        'import_run_id' => 999999,
        'row_number' => 1,
        'external_cid' => 'x',
        'username' => 'y',
        'reason' => ImportRunFailureReason::IdentityConflictWithinPage->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('cascades delete: removing the parent ImportRun deletes its failure rows', function (): void {
    $importRun = ImportRun::factory()->create();
    $failure = ImportRunFailure::factory()->create(['import_run_id' => $importRun->id]);

    $importRun->delete();

    expect(ImportRunFailure::query()->whereKey($failure->id)->exists())->toBeFalse();
});

it('enforces a unique constraint on (import_run_id, row_number)', function (): void {
    $importRun = ImportRun::factory()->create();
    ImportRunFailure::factory()->create(['import_run_id' => $importRun->id, 'row_number' => 1]);

    expect(fn () => ImportRunFailure::factory()->create(['import_run_id' => $importRun->id, 'row_number' => 1]))
        ->toThrow(QueryException::class);
});

it('allows the same row_number to repeat across different import runs', function (): void {
    $runA = ImportRun::factory()->create();
    $runB = ImportRun::factory()->create();

    ImportRunFailure::factory()->create(['import_run_id' => $runA->id, 'row_number' => 1]);
    $failureB = ImportRunFailure::factory()->create(['import_run_id' => $runB->id, 'row_number' => 1]);

    expect($failureB)->not->toBeNull();
    expect(ImportRunFailure::query()->count())->toBe(2);
});

// --- childFailures(): aggregate -> direct childRuns -> failures, no duplication ---

it('childFailures() reaches a rankings_discovery aggregate run\'s DIRECT child runs\' failures without duplicating rows on the aggregate itself', function (): void {
    $aggregateRun = ImportRun::factory()->create(['type' => 'rankings_discovery']);
    $childA = ImportRun::factory()->create(['parent_import_run_id' => $aggregateRun->id, 'type' => 'rankings']);
    $childB = ImportRun::factory()->create(['parent_import_run_id' => $aggregateRun->id, 'type' => 'rankings']);

    $failureA = ImportRunFailure::factory()->create(['import_run_id' => $childA->id, 'row_number' => 1]);
    $failureB = ImportRunFailure::factory()->create(['import_run_id' => $childB->id, 'row_number' => 1]);

    // Both joined tables (import_runs, import_run_failures) have their own
    // `id` column, so the pluck column must be qualified to avoid an
    // "ambiguous column name" error from the self-join.
    $childFailureIds = $aggregateRun->childFailures()->pluck('import_run_failures.id')->sort()->values();

    expect($childFailureIds->all())->toBe(collect([$failureA->id, $failureB->id])->sort()->values()->all());

    // The aggregate itself never owns a failure row directly.
    expect($aggregateRun->failures()->count())->toBe(0);
});
