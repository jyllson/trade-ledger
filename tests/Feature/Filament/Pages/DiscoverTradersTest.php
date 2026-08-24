<?php

use App\Filament\Pages\DiscoverTraders;
use App\Models\ImportRun;
use App\Models\Trader;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const DISCOVER_PAGE_RANKINGS_URL = 'https://public-api.etoro.com/api/v2/portfolios/rankings*';

const DISCOVER_PAGE_PROFILE_URL = 'https://public-api.etoro.com/api/v1/user-info/people*';

/**
 * @return array<string, mixed>
 */
function discoverPageRankingsPayload(array $entries, int $page, int $pageSize, int $totalItems, bool $hasNext): array
{
    return [
        'results' => $entries,
        'pagination' => ['page' => $page, 'pageSize' => $pageSize, 'totalItems' => $totalItems, 'hasNext' => $hasNext],
    ];
}

/**
 * @return array<string, mixed>
 */
function discoverPageEntry(string $cid, string $username): array
{
    return ['cid' => $cid, 'username' => $username, 'type' => 'trader', 'subType' => 'pi-certified', 'copiers' => 100];
}

/**
 * @return array<string, mixed>
 */
function discoverPageProfilePayload(string $username = 'trader_001', bool $isPi = true): array
{
    return [
        'users' => [[
            'gcid' => '900001',
            'username' => $username,
            'isPi' => $isPi,
            'isVerified' => true,
            'country' => 1,
            'languageIsoCode' => 'en-US',
        ]],
    ];
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

it('redirects guests away from the discover traders page', function () {
    auth()->logout();

    $this->get('/admin/discover-traders')->assertRedirect('/admin/login');
});

it('renders the page with NO HTTP call at all', function () {
    Livewire::test(DiscoverTraders::class)->assertOk();

    Http::assertNothingSent();
});

it('shows the read-only safety callout', function () {
    Livewire::test(DiscoverTraders::class)
        ->assertSee('Read-only, on demand');
});

// --- Run discovery: validation -------------------------------------------

it('does not call discovery when the period is blank', function () {
    Livewire::test(DiscoverTraders::class)
        ->mountAction('runDiscovery')
        ->setActionData(['period' => '', 'start_page' => 1, 'max_pages' => 1])
        ->callMountedAction()
        ->assertHasActionErrors(['period']);

    Http::assertNothingSent();
    expect(ImportRun::query()->count())->toBe(0);
});

it('does not call discovery when max_pages is above the ceiling of 20', function () {
    Livewire::test(DiscoverTraders::class)
        ->mountAction('runDiscovery')
        ->setActionData(['period' => 'lastYear', 'start_page' => 1, 'max_pages' => 21])
        ->callMountedAction()
        ->assertHasActionErrors(['max_pages']);

    Http::assertNothingSent();
});

it('does not call discovery when start_page is below 1', function () {
    Livewire::test(DiscoverTraders::class)
        ->mountAction('runDiscovery')
        ->setActionData(['period' => 'lastYear', 'start_page' => 0, 'max_pages' => 1])
        ->callMountedAction()
        ->assertHasActionErrors(['start_page']);

    Http::assertNothingSent();
});

// --- Run discovery: outcomes ----------------------------------------------

it('runs a Completed discovery, shows a success notification, and records the result with a run link', function () {
    Http::fake([
        DISCOVER_PAGE_RANKINGS_URL => Http::response(
            discoverPageRankingsPayload([discoverPageEntry('3001', 'trader_x')], page: 1, pageSize: 20, totalItems: 1, hasNext: false),
            200,
        ),
    ]);

    $component = Livewire::test(DiscoverTraders::class)
        ->callAction('runDiscovery', data: ['period' => 'lastYear', 'start_page' => 1, 'max_pages' => 5])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $run = ImportRun::query()->where('type', 'rankings_discovery')->firstOrFail();

    expect($run->status->value)->toBe('completed');
    expect($component->get('lastDiscoveryResult')['run_id'])->toBe($run->id);
    expect($component->get('lastDiscoveryResult')['run_url'])->toContain((string) $run->id);

    $component->assertSee('#'.$run->id);

    expect(Trader::query()->count())->toBe(1);
});

it('runs a Partial discovery (controlled row rejection) and notifies with a warning status', function () {
    Trader::factory()->create(['external_cid' => 'existing-cid', 'username' => 'trader_a']);

    Http::fake([
        DISCOVER_PAGE_RANKINGS_URL => Http::response(
            discoverPageRankingsPayload([
                discoverPageEntry('4001', 'trader_a'),
                discoverPageEntry('4002', 'trader_b'),
            ], page: 1, pageSize: 20, totalItems: 2, hasNext: false),
            200,
        ),
    ]);

    Livewire::test(DiscoverTraders::class)
        ->callAction('runDiscovery', data: ['period' => 'lastYear', 'start_page' => 1, 'max_pages' => 5])
        ->assertNotified();

    $run = ImportRun::query()->where('type', 'rankings_discovery')->firstOrFail();
    expect($run->status->value)->toBe('partial');
});

it('runs a Failed discovery (configuration error) and notifies with a danger status', function () {
    config(['etoro.enabled' => false]);

    Livewire::test(DiscoverTraders::class)
        ->callAction('runDiscovery', data: ['period' => 'lastYear', 'start_page' => 1, 'max_pages' => 5])
        ->assertNotified();

    $run = ImportRun::query()->where('type', 'rankings_discovery')->firstOrFail();
    expect($run->status->value)->toBe('failed');

    Http::assertNothingSent();
});

// --- Lookup profile ---------------------------------------------------------

it('looks up a matching eToro profile and records a completed result with a run link', function () {
    Trader::factory()->create(['username' => 'trader_001']);

    Http::fake([DISCOVER_PAGE_PROFILE_URL => Http::response(discoverPageProfilePayload(username: 'trader_001'), 200)]);

    $component = Livewire::test(DiscoverTraders::class)
        ->callAction('lookupProfile', data: ['username' => 'trader_001'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $run = ImportRun::query()->where('type', 'profile')->firstOrFail();

    expect($component->get('lastProfileLookupResult')['completed'])->toBeTrue()
        ->and($component->get('lastProfileLookupResult')['matched_local_trader'])->toBeTrue()
        ->and($component->get('lastProfileLookupResult')['run_id'])->toBe($run->id);

    $component->assertSee('#'.$run->id);
});

it('looks up an unknown eToro profile: records the run, marks it as no local match, and creates no Trader', function () {
    Http::fake([DISCOVER_PAGE_PROFILE_URL => Http::response(discoverPageProfilePayload(username: 'unknown_trader'), 200)]);

    $component = Livewire::test(DiscoverTraders::class)
        ->callAction('lookupProfile', data: ['username' => 'unknown_trader'])
        ->assertNotified();

    expect($component->get('lastProfileLookupResult')['completed'])->toBeTrue()
        ->and($component->get('lastProfileLookupResult')['matched_local_trader'])->toBeFalse();

    expect(Trader::query()->count())->toBe(0);
});

it('does not call lookup when the username is blank', function () {
    Livewire::test(DiscoverTraders::class)
        ->mountAction('lookupProfile')
        ->setActionData(['username' => ''])
        ->callMountedAction()
        ->assertHasActionErrors(['username']);

    Http::assertNothingSent();
});

it('a failed profile lookup (e.g. request failure) never claims a match or no-match — only that it did not complete', function () {
    Http::fake([DISCOVER_PAGE_PROFILE_URL => Http::response(['error' => 'boom'], 500)]);

    $component = Livewire::test(DiscoverTraders::class)
        ->callAction('lookupProfile', data: ['username' => 'trader_001'])
        ->assertNotified();

    expect($component->get('lastProfileLookupResult')['completed'])->toBeFalse();

    $component->assertSee('The lookup did not complete')
        ->assertDontSee('matched a locally-known trader')
        ->assertDontSee('did not match any locally-known trader');

    expect(Trader::query()->count())->toBe(0);
});

it('never renders eToro credentials on the discover traders page', function () {
    Livewire::test(DiscoverTraders::class)
        ->assertDontSee('test-api-key-value-sentinel')
        ->assertDontSee('test-user-key-value-sentinel');
});
