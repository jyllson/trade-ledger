<?php

use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates an import_runs table with the expected columns', function (): void {
    expect(Schema::hasTable('import_runs'))->toBeTrue();

    expect(Schema::hasColumns('import_runs', [
        'id',
        'parent_import_run_id',
        'source',
        'type',
        'status',
        'metadata',
        'request_count',
        'success_count',
        'failure_count',
        'started_at',
        'finished_at',
        'error_summary',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

// --- Checkpoint E: parent_import_run_id self-relation -----------------------

it('allows a null parent_import_run_id', function (): void {
    $importRun = ImportRun::factory()->create(['parent_import_run_id' => null]);

    $fromDatabase = ImportRun::query()->findOrFail($importRun->id);

    expect($fromDatabase->parent_import_run_id)->toBeNull();
});

it('casts parent_import_run_id to an integer', function (): void {
    $parent = ImportRun::factory()->create();
    $child = ImportRun::factory()->create(['parent_import_run_id' => $parent->id]);

    $fromDatabase = ImportRun::query()->findOrFail($child->id);

    expect($fromDatabase->parent_import_run_id)->toBe($parent->id)->toBeInt();
});

it('exposes explicit parentRun and childRuns relations', function (): void {
    $parent = ImportRun::factory()->create(['type' => 'rankings_discovery']);
    $childA = ImportRun::factory()->create(['parent_import_run_id' => $parent->id, 'type' => 'rankings']);
    $childB = ImportRun::factory()->create(['parent_import_run_id' => $parent->id, 'type' => 'rankings']);
    ImportRun::factory()->create(['parent_import_run_id' => null]);

    expect($childA->parentRun)->not->toBeNull()
        ->and($childA->parentRun->id)->toBe($parent->id);

    $children = $parent->childRuns()->pluck('id')->sort()->values();

    expect($children->all())->toBe(collect([$childA->id, $childB->id])->sort()->values()->all());
});

it('nulls parent_import_run_id on children when the parent run is deleted', function (): void {
    $parent = ImportRun::factory()->create(['type' => 'rankings_discovery']);
    $child = ImportRun::factory()->create(['parent_import_run_id' => $parent->id, 'type' => 'rankings']);

    $parent->delete();

    expect($child->refresh()->parent_import_run_id)->toBeNull();
});

it('indexes the type column', function (): void {
    $indexedColumns = collect(Schema::getIndexes('import_runs'))
        ->flatMap(fn (array $index): array => $index['columns']);

    expect($indexedColumns)->toContain('type');
});

it('enforces a foreign key constraint pointing back at import_runs.id', function (): void {
    expect(fn () => DB::table('import_runs')->insert([
        'parent_import_run_id' => 999999,
        'source' => 'etoro',
        'type' => 'rankings',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('defaults a newly inserted import run status to pending', function (): void {
    $id = DB::table('import_runs')->insertGetId([
        'source' => 'etoro',
        'type' => 'rankings',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $importRun = ImportRun::query()->findOrFail($id);

    expect($importRun->status)->toBe(ImportRunStatus::Pending);
});

it('casts status to the ImportRunStatus backed enum', function (): void {
    $importRun = ImportRun::factory()->create(['status' => ImportRunStatus::Failed]);

    $fromDatabase = ImportRun::query()->findOrFail($importRun->id);

    expect($fromDatabase->status)->toBeInstanceOf(ImportRunStatus::class)
        ->and($fromDatabase->status)->toBe(ImportRunStatus::Failed);

    expect(DB::table('import_runs')->where('id', $importRun->id)->value('status'))->toBe('failed');
});

it('casts metadata to an array via a JSON column', function (): void {
    $importRun = ImportRun::factory()->create([
        'metadata' => ['period' => 'lastYear', 'pageSize' => 20],
    ]);

    $fromDatabase = ImportRun::query()->findOrFail($importRun->id);

    expect($fromDatabase->metadata)->toBe(['period' => 'lastYear', 'pageSize' => 20]);
});

it('allows a null metadata value', function (): void {
    $importRun = ImportRun::factory()->create(['metadata' => null]);

    $fromDatabase = ImportRun::query()->findOrFail($importRun->id);

    expect($fromDatabase->metadata)->toBeNull();
});

it('casts started_at and finished_at to Carbon instances', function (): void {
    $importRun = ImportRun::factory()->create([
        'started_at' => '2026-01-01 00:00:00',
        'finished_at' => '2026-01-01 00:05:00',
    ]);

    $fromDatabase = ImportRun::query()->findOrFail($importRun->id);

    expect($fromDatabase->started_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($fromDatabase->finished_at)->toBeInstanceOf(CarbonInterface::class);
});

it('allows a null finished_at value', function (): void {
    $importRun = ImportRun::factory()->create(['finished_at' => null]);

    $fromDatabase = ImportRun::query()->findOrFail($importRun->id);

    expect($fromDatabase->finished_at)->toBeNull();
});

it('casts request, success, and failure counts to integers and defaults them to zero', function (): void {
    $id = DB::table('import_runs')->insertGetId([
        'source' => 'etoro',
        'type' => 'rankings',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $importRun = ImportRun::query()->findOrFail($id);

    expect($importRun->request_count)->toBe(0)->toBeInt()
        ->and($importRun->success_count)->toBe(0)->toBeInt()
        ->and($importRun->failure_count)->toBe(0)->toBeInt();
});
