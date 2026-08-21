<?php

use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates an import_runs table with the expected columns', function (): void {
    expect(Schema::hasTable('import_runs'))->toBeTrue();

    expect(Schema::hasColumns('import_runs', [
        'id',
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
