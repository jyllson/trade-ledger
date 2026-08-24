<?php

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\ChildFailuresRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\ChildRunsRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\FailuresRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\RetryAttemptsRelationManager;
use App\Filament\Resources\ImportRuns\Schemas\ImportRunInfolist;
use App\Filament\Resources\ImportRuns\Tables\ImportRunsTable;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint H2 architectural guarantees for every App\Filament\Resources\
 * ImportRuns class: no direct EtoroClient/HTTP/DB/config/env/Storage/Log/
 * Queue dependency, no direct ImportRun mutation outside
 * RetryEtoroTraderDiscovery, and no locally duplicated retry-eligibility
 * logic — visibility must always route through canRetry(), never a
 * re-implemented retryable/stop_reason/category check.
 */
function checkpointH2ImportRunFilamentClasses(): array
{
    return [
        ImportRunResource::class,
        ListImportRuns::class,
        ViewImportRun::class,
        ImportRunInfolist::class,
        ImportRunsTable::class,
        ChildRunsRelationManager::class,
        FailuresRelationManager::class,
        ChildFailuresRelationManager::class,
        RetryAttemptsRelationManager::class,
    ];
}

function checkpointH2ImportRunCodeWithoutComments(ReflectionClass $reflection): string
{
    $source = File::get($reflection->getFileName());
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('no App\\Filament\\Resources\\ImportRuns class uses EtoroClient, HTTP, config/env, Storage/Log/DB/Queue directly', function (string $class) {
    $forbiddenSubstrings = [
        'EtoroClient',
        'Illuminate\\Support\\Facades\\Http',
        'Illuminate\\Http\\Client',
        'Http::',
        'config(',
        'env(',
        'Storage::',
        'Log::',
        'DB::',
        'Queue::',
        'dispatch(',
        'GuzzleHttp',
    ];

    $code = checkpointH2ImportRunCodeWithoutComments(new ReflectionClass($class));

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
})->with(checkpointH2ImportRunFilamentClasses());

it('no App\\Filament\\Resources\\ImportRuns class creates/deletes/force-saves an ImportRun directly — only RetryEtoroTraderDiscovery ever writes', function (string $class) {
    $forbiddenSubstrings = [
        'ImportRun::create(',
        '$record->forceFill(',
        '$record->delete(',
    ];

    $code = checkpointH2ImportRunCodeWithoutComments(new ReflectionClass($class));

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
})->with(checkpointH2ImportRunFilamentClasses());

it('ImportRunsTable only ever calls RetryEtoroTraderDiscovery::canRetry() and ::handle() — never re-implements retry eligibility itself', function (): void {
    $code = checkpointH2ImportRunCodeWithoutComments(new ReflectionClass(ImportRunsTable::class));

    expect($code)->toContain('RetryEtoroTraderDiscovery::class)->canRetry(')
        ->toContain('RetryEtoroTraderDiscovery::class)->handle(');

    // Metadata is read for sanitized DISPLAY only (e.g. the confirmation
    // modal's period/start_page/max_pages) — never to gate the retry
    // action's own visibility, which must always be canRetry() alone.
    foreach (["metadata['retryable']", '->metadata["retryable"]', "'retryable' =>", 'stop_reason ==='] as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('ImportRunInfolist never renders the raw metadata array directly, only individually whitelisted keys via data_get()', function (): void {
    $code = checkpointH2ImportRunCodeWithoutComments(new ReflectionClass(ImportRunInfolist::class));

    expect($code)->not->toContain('TextEntry::make(\'metadata\')')
        ->not->toContain('json_encode($record->metadata')
        ->not->toContain('->state(fn (ImportRun $record): mixed => $record->metadata)');

    expect($code)->toContain('data_get(');
});

it('ImportRunResource has no Create or Edit page and canCreate() is false', function (): void {
    expect(ImportRunResource::hasPage('create'))->toBeFalse();
    expect(ImportRunResource::hasPage('edit'))->toBeFalse();
    expect(ImportRunResource::canCreate())->toBeFalse();
});

it('no relation manager registers a create/edit/delete action', function (string $class) {
    $code = checkpointH2ImportRunCodeWithoutComments(new ReflectionClass($class));

    foreach (['CreateAction', 'EditAction', 'DeleteAction', 'DeleteBulkAction'] as $needle) {
        expect($code)->not->toContain($needle);
    }
})->with([
    ChildRunsRelationManager::class,
    FailuresRelationManager::class,
    ChildFailuresRelationManager::class,
    RetryAttemptsRelationManager::class,
]);
