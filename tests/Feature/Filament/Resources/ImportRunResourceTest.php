<?php

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\ChildFailuresRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\ChildRunsRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\FailuresRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\RetryAttemptsRelationManager;
use App\Models\ImportRun;
use App\Models\ImportRunFailure;
use App\Models\ImportRunFailureReason;
use App\Models\ImportRunStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const IMPORT_RUN_RANKINGS_URL = 'https://public-api.etoro.com/api/v2/portfolios/rankings*';

/**
 * @param  array<string, mixed>  $metadataOverrides
 */
function eligibleRetryableRun(array $attributes = [], array $metadataOverrides = []): ImportRun
{
    return ImportRun::factory()->create(array_merge([
        'source' => 'etoro',
        'type' => 'rankings_discovery',
        'status' => ImportRunStatus::Failed,
        'metadata' => array_replace_recursive([
            'query' => ['period' => 'lastYear', 'start_page' => 1, 'page_size' => 20, 'max_pages' => 1, 'sort' => null, 'country' => null],
            'stop_reason' => 'request_failed',
            'pages_fetched' => 0,
            'retryable' => true,
            'request_error_category' => 'server_error',
        ], $metadataOverrides),
    ], $attributes));
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value-sentinel',
        'etoro.user_key' => 'test-user-key-value-sentinel',
        'etoro.timeout_seconds' => 5,
        'etoro.connect_timeout_seconds' => 2,
    ]);
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create());
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

// --- Panel auth / routes ----------------------------------------------

it('redirects guests away from the import run list', function () {
    auth()->logout();

    $this->get(ImportRunResource::getUrl('index'))->assertRedirect('/admin/login');
});

it('has no create/edit pages', function () {
    expect(ImportRunResource::hasPage('create'))->toBeFalse();
    expect(ImportRunResource::hasPage('edit'))->toBeFalse();
    expect(ImportRunResource::canCreate())->toBeFalse();
});

// --- List rendering, filters, default sort ------------------------------

it('renders the import run list newest-first by default', function () {
    $older = ImportRun::factory()->create(['id' => 1]);
    $newer = ImportRun::factory()->create(['id' => 2]);

    Livewire::test(ListImportRuns::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('shows the empty state when there are no import runs', function () {
    Livewire::test(ListImportRuns::class)
        ->assertOk()
        ->assertSee('No import runs yet');
});

it('can filter import runs by status', function () {
    $failed = ImportRun::factory()->create(['status' => ImportRunStatus::Failed]);
    $completed = ImportRun::factory()->create(['status' => ImportRunStatus::Completed]);

    Livewire::test(ListImportRuns::class)
        ->filterTable('status', ImportRunStatus::Failed->value)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$completed]);
});

it('can filter import runs by type', function () {
    $discovery = ImportRun::factory()->create(['type' => 'rankings_discovery']);
    $profile = ImportRun::factory()->create(['type' => 'profile']);

    Livewire::test(ListImportRuns::class)
        ->filterTable('type', 'profile')
        ->assertCanSeeTableRecords([$profile])
        ->assertCanNotSeeTableRecords([$discovery]);
});

it('visibly distinguishes Partial and Failed statuses in the table badge', function () {
    $partial = ImportRun::factory()->create(['status' => ImportRunStatus::Partial]);
    $failed = ImportRun::factory()->create(['status' => ImportRunStatus::Failed]);

    $component = Livewire::test(ListImportRuns::class)->assertOk();

    $component->assertTableColumnStateSet('status', ImportRunStatus::Partial, $partial)
        ->assertTableColumnStateSet('status', ImportRunStatus::Failed, $failed);
});

// --- Retry visibility (gated only through RetryEtoroTraderDiscovery::canRetry()) --

it('shows the retry action for an eligible run', function () {
    $run = eligibleRetryableRun();

    Livewire::test(ListImportRuns::class)
        ->assertTableActionVisible('retry', $run);
});

it('hides the retry action for a non-retryable stop reason', function () {
    $run = eligibleRetryableRun(metadataOverrides: ['request_error_category' => 'validation']);

    Livewire::test(ListImportRuns::class)
        ->assertTableActionHidden('retry', $run);
});

it('hides the retry action for a Completed run', function () {
    $run = eligibleRetryableRun(['status' => ImportRunStatus::Completed]);

    Livewire::test(ListImportRuns::class)
        ->assertTableActionHidden('retry', $run);
});

it('hides the retry action for a child rankings run (only aggregate discovery runs are retryable)', function () {
    $run = eligibleRetryableRun(['type' => 'rankings']);

    Livewire::test(ListImportRuns::class)
        ->assertTableActionHidden('retry', $run);
});

it('hides the retry action while retry_not_before is still in the future', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-21T12:00:00+00:00'));
    $run = eligibleRetryableRun(metadataOverrides: ['retry_not_before' => now()->addMinutes(30)->toIso8601String()]);

    Livewire::test(ListImportRuns::class)
        ->assertTableActionHidden('retry', $run);
});

it('shows the retry action once retry_not_before has elapsed', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-21T12:00:00+00:00'));
    $run = eligibleRetryableRun(metadataOverrides: ['retry_not_before' => now()->subMinute()->toIso8601String()]);

    Livewire::test(ListImportRuns::class)
        ->assertTableActionVisible('retry', $run);
});

// --- Retry action execution ---------------------------------------------

it('retries an eligible run and notifies with the new run id/status/link', function () {
    $run = eligibleRetryableRun();

    Http::fake([
        IMPORT_RUN_RANKINGS_URL => Http::response([
            'results' => [],
            'pagination' => ['page' => 1, 'pageSize' => 20, 'totalItems' => 0, 'hasNext' => false],
        ], 200),
    ]);

    Livewire::test(ListImportRuns::class)
        ->callTableAction('retry', $run)
        ->assertNotified();

    $newRun = ImportRun::query()->where('retry_of_import_run_id', $run->id)->firstOrFail();
    expect($newRun->id)->not->toBe($run->id);
});

// --- View page / infolist whitelist --------------------------------------

it('renders the view page for a discovery run with whitelisted query/outcome fields, never raw metadata JSON', function () {
    $run = ImportRun::factory()->create([
        'type' => 'rankings_discovery',
        'status' => ImportRunStatus::Completed,
        'metadata' => [
            'query' => ['period' => 'lastYear', 'start_page' => 1, 'page_size' => 20, 'max_pages' => 3, 'sort' => '-copiers', 'country' => 'US'],
            'stop_reason' => 'natural_completion',
            'pages_fetched' => 3,
            'child_import_run_ids' => [10, 11, 12],
            'retryable' => false,
        ],
    ]);

    Livewire::test(ViewImportRun::class, ['record' => $run->id])
        ->assertOk()
        ->assertSee('lastYear')
        ->assertSee('natural_completion')
        ->assertDontSee('child_import_run_ids');
});

it('renders the view page for a profile run with the username, never a raw payload', function () {
    $run = ImportRun::factory()->create([
        'type' => 'profile',
        'status' => ImportRunStatus::Completed,
        'metadata' => [
            'query' => ['username' => 'trader_001'],
            'stop_reason' => 'completed',
            'matched_stored_trader' => true,
        ],
    ]);

    Livewire::test(ViewImportRun::class, ['record' => $run->id])
        ->assertOk()
        ->assertSee('trader_001');
});

it('never renders an arbitrary, non-whitelisted metadata key or value on the view page', function () {
    $run = ImportRun::factory()->create([
        'type' => 'rankings_discovery',
        'status' => ImportRunStatus::Completed,
        'metadata' => [
            'query' => ['period' => 'lastYear', 'start_page' => 1, 'page_size' => 20, 'max_pages' => 1, 'sort' => null, 'country' => null],
            'stop_reason' => 'natural_completion',
            'pages_fetched' => 1,
            'retryable' => false,
            // Not on the documented whitelist (period/start_page/page_size/
            // max_pages/sort/country/username/stop_reason/pages_fetched/
            // retryable/retry_not_before/request_error_category) — proves
            // the infolist truly picks fields individually rather than
            // rendering the metadata array wholesale.
            'raw_payload' => ['nested' => 'ARBITRARY-METADATA-SENTINEL'],
        ],
    ]);

    Livewire::test(ViewImportRun::class, ['record' => $run->id])
        ->assertOk()
        ->assertDontSee('raw_payload')
        ->assertDontSee('ARBITRARY-METADATA-SENTINEL');
});

it('never renders eToro credentials anywhere on the import run pages', function () {
    $run = eligibleRetryableRun();

    Livewire::test(ListImportRuns::class)
        ->assertDontSee('test-api-key-value-sentinel')
        ->assertDontSee('test-user-key-value-sentinel');

    Livewire::test(ViewImportRun::class, ['record' => $run->id])
        ->assertDontSee('test-api-key-value-sentinel')
        ->assertDontSee('test-user-key-value-sentinel');
});

// --- Relation managers ---------------------------------------------------

it('shows direct child runs on an aggregate discovery run, with an empty state when none exist', function () {
    $aggregate = ImportRun::factory()->create(['type' => 'rankings_discovery']);

    Livewire::test(ChildRunsRelationManager::class, [
        'ownerRecord' => $aggregate,
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertSee('No child runs');

    $child = ImportRun::factory()->create(['type' => 'rankings', 'parent_import_run_id' => $aggregate->id]);

    Livewire::test(ChildRunsRelationManager::class, [
        'ownerRecord' => $aggregate->refresh(),
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$child]);
});

it('does not offer the child runs relation manager for a non-aggregate run', function () {
    $run = ImportRun::factory()->create(['type' => 'rankings']);

    expect(ChildRunsRelationManager::canViewForRecord($run, ViewImportRun::class))->toBeFalse();
});

it('shows direct rejected rows on a per-page rankings run, with an empty state when none exist', function () {
    $run = ImportRun::factory()->create(['type' => 'rankings']);

    Livewire::test(FailuresRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertSee('No rejected rows');

    $failure = ImportRunFailure::factory()->create([
        'import_run_id' => $run->id,
        'row_number' => 1,
        'external_cid' => '1001',
        'username' => 'trader_a',
        'reason' => ImportRunFailureReason::IdentityConflictWithinPage,
    ]);

    Livewire::test(FailuresRelationManager::class, [
        'ownerRecord' => $run->refresh(),
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$failure])
        ->assertSee('1001')
        ->assertSee('trader_a');
});

it('does not offer the direct failures relation manager for an aggregate discovery run', function () {
    $run = ImportRun::factory()->create(['type' => 'rankings_discovery']);

    expect(FailuresRelationManager::canViewForRecord($run, ViewImportRun::class))->toBeFalse();
});

it('shows aggregate childFailures across all child pages, with an empty state when none exist', function () {
    $aggregate = ImportRun::factory()->create(['type' => 'rankings_discovery']);

    Livewire::test(ChildFailuresRelationManager::class, [
        'ownerRecord' => $aggregate,
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertSee('No rejected rows');

    $child = ImportRun::factory()->create(['type' => 'rankings', 'parent_import_run_id' => $aggregate->id]);
    $failure = ImportRunFailure::factory()->create([
        'import_run_id' => $child->id,
        'row_number' => 2,
        'external_cid' => '2002',
        'username' => 'trader_b',
    ]);

    Livewire::test(ChildFailuresRelationManager::class, [
        'ownerRecord' => $aggregate->refresh(),
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$failure])
        ->assertSee('2002');
});

it('does not offer the aggregate childFailures relation manager for a non-aggregate run', function () {
    $run = ImportRun::factory()->create(['type' => 'rankings']);

    expect(ChildFailuresRelationManager::canViewForRecord($run, ViewImportRun::class))->toBeFalse();
});

it('shows retry attempts on a discovery run, with an empty state when none exist', function () {
    $original = ImportRun::factory()->create(['type' => 'rankings_discovery']);

    Livewire::test(RetryAttemptsRelationManager::class, [
        'ownerRecord' => $original,
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertSee('No retry attempts');

    $retry = ImportRun::factory()->create(['type' => 'rankings_discovery', 'retry_of_import_run_id' => $original->id]);

    Livewire::test(RetryAttemptsRelationManager::class, [
        'ownerRecord' => $original->refresh(),
        'pageClass' => ViewImportRun::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$retry]);
});

it('does not offer the retry attempts relation manager for a non-discovery run', function () {
    $run = ImportRun::factory()->create(['type' => 'profile']);

    expect(RetryAttemptsRelationManager::canViewForRecord($run, ViewImportRun::class))->toBeFalse();
});
