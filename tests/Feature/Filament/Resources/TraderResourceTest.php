<?php

use App\Application\Traders\ProfileFreshness;
use App\Filament\Resources\Traders\Pages\ListTraders;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Filament\Resources\Traders\TraderResource;
use App\Models\ImportRun;
use App\Models\Trader;
use App\Models\TraderStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications as NotificationsLivewireComponent;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Every notification actually sent during the current test, read directly
 * from Filament's own testing sink — not scraped from rendered HTML, which
 * notifications are not guaranteed to appear in.
 *
 * @return Collection<int, Notification>
 */
function traderResourceTestSentNotifications(): Collection
{
    $component = new NotificationsLivewireComponent;
    $component->mount();

    return $component->notifications;
}

const TRADER_PROFILE_LOOKUP_URL = 'https://public-api.etoro.com/api/v1/user-info/people*';

/**
 * @return array<string, mixed>
 */
function traderProfileLookupPayload(
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
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

// --- Panel auth --------------------------------------------------------

it('redirects guests away from the trader list', function () {
    $this->get(TraderResource::getUrl('index'))->assertRedirect('/admin/login');
});

it('redirects guests away from a trader view page', function () {
    $trader = Trader::factory()->create();

    $this->get(TraderResource::getUrl('view', ['record' => $trader]))->assertRedirect('/admin/login');
});

it('has no create/edit routes at all', function () {
    expect(TraderResource::hasPage('create'))->toBeFalse();
    expect(TraderResource::hasPage('edit'))->toBeFalse();
    expect(TraderResource::canCreate())->toBeFalse();
});

// --- List rendering, columns, search/sort/filter ------------------------

it('renders the trader list with no HTTP call', function () {
    $this->actingAs(User::factory()->create());
    $traders = Trader::factory()->count(3)->create();

    Livewire::test(ListTraders::class)
        ->assertOk()
        ->assertCanSeeTableRecords($traders);

    Http::assertNothingSent();
});

it('shows the empty state when there are no traders', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListTraders::class)
        ->assertOk()
        ->assertSee('No traders imported yet');
});

it('can search traders by username', function () {
    $this->actingAs(User::factory()->create());
    $match = Trader::factory()->create(['username' => 'unique_search_target']);
    $other = Trader::factory()->create(['username' => 'someone_else']);

    Livewire::test(ListTraders::class)
        ->searchTable('unique_search_target')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('can sort traders by username', function () {
    $this->actingAs(User::factory()->create());
    $traders = collect(['charlie', 'alpha', 'bravo'])
        ->map(fn (string $username): Trader => Trader::factory()->create(['username' => $username]));

    Livewire::test(ListTraders::class)
        ->sortTable('username')
        ->assertCanSeeTableRecords($traders->sortBy('username'), inOrder: true);
});

it('can filter traders by status', function () {
    $this->actingAs(User::factory()->create());
    $watched = Trader::factory()->create(['status' => TraderStatus::Watched]);
    $candidate = Trader::factory()->create(['status' => TraderStatus::Candidate]);

    Livewire::test(ListTraders::class)
        ->filterTable('status', TraderStatus::Watched->value)
        ->assertCanSeeTableRecords([$watched])
        ->assertCanNotSeeTableRecords([$candidate]);
});

it('renders the trader view page with observed ranking identity, observed profile fields, and freshness — no HTTP call', function () {
    $this->actingAs(User::factory()->create());
    Carbon::setTestNow(Carbon::parse('2026-08-21T12:00:00+00:00'));

    $trader = Trader::factory()->create([
        'external_cid' => '100001',
        'username' => 'trader_001',
        'ranking_type' => 'trader',
        'ranking_sub_type' => 'pi-certified',
        'copiers_count' => 4242,
        'status' => TraderStatus::Watched,
        'profile_gcid' => '900001',
        'profile_is_popular_investor' => true,
        'profile_is_verified' => true,
        'profile_country_code' => 1,
        'profile_language_iso_code' => 'en-US',
        'profile_synced_at' => now()->subHours(2),
    ]);

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertOk()
        ->assertSee('100001')
        ->assertSee('trader_001')
        ->assertSee('4,242')
        ->assertSee('pi-certified')
        ->assertSee('Watched')
        ->assertSee('900001')
        ->assertSee('en-US')
        ->assertSee('Fresh');

    Http::assertNothingSent();
});

it('renders never-synced profile fields as an explicit placeholder, not blank', function () {
    $this->actingAs(User::factory()->create());
    $trader = Trader::factory()->create(['profile_synced_at' => null, 'profile_gcid' => null]);

    Livewire::test(ViewTrader::class, ['record' => $trader->id])
        ->assertOk()
        ->assertSee('Never synced')
        ->assertSee('Not yet observed');
});

it('renders the profile freshness badge as never_synced, fresh, and stale for the respective traders', function () {
    $this->actingAs(User::factory()->create());
    Carbon::setTestNow(Carbon::parse('2026-08-21T12:00:00+00:00'));

    $neverSynced = Trader::factory()->create(['profile_synced_at' => null]);
    $fresh = Trader::factory()->create(['profile_synced_at' => now()->subHours(2)]);
    $stale = Trader::factory()->create(['profile_synced_at' => now()->subHours(30)]);

    $component = Livewire::test(ListTraders::class)->assertOk();

    $component->assertTableColumnStateSet('freshness', ProfileFreshness::NeverSynced, $neverSynced)
        ->assertTableColumnStateSet('freshness', ProfileFreshness::Fresh, $fresh)
        ->assertTableColumnStateSet('freshness', ProfileFreshness::Stale, $stale);
});

// --- Triage actions -------------------------------------------------------

it('hides the mark-candidate action for a trader already candidate, and shows it otherwise', function () {
    $this->actingAs(User::factory()->create());
    $candidate = Trader::factory()->create(['status' => TraderStatus::Candidate]);
    $watched = Trader::factory()->create(['status' => TraderStatus::Watched]);

    Livewire::test(ListTraders::class)
        ->assertTableActionHidden('markCandidate', $candidate)
        ->assertTableActionVisible('markCandidate', $watched);
});

it('hides the watch action for a trader already watched, and shows it otherwise', function () {
    $this->actingAs(User::factory()->create());
    $watched = Trader::factory()->create(['status' => TraderStatus::Watched]);
    $candidate = Trader::factory()->create(['status' => TraderStatus::Candidate]);

    Livewire::test(ListTraders::class)
        ->assertTableActionHidden('markWatched', $watched)
        ->assertTableActionVisible('markWatched', $candidate);
});

it('hides the ignore action for a trader already ignored, and shows it otherwise', function () {
    $this->actingAs(User::factory()->create());
    $ignored = Trader::factory()->create(['status' => TraderStatus::Ignored]);
    $candidate = Trader::factory()->create(['status' => TraderStatus::Candidate]);

    Livewire::test(ListTraders::class)
        ->assertTableActionHidden('markIgnored', $ignored)
        ->assertTableActionVisible('markIgnored', $candidate);
});

it('changes a trader status to watched via the table action and notifies', function () {
    $this->actingAs(User::factory()->create());
    $trader = Trader::factory()->create(['status' => TraderStatus::Candidate]);

    Livewire::test(ListTraders::class)
        ->callTableAction('markWatched', $trader)
        ->assertNotified('Trader status updated: Watch');

    expect(Trader::query()->findOrFail($trader->id)->status)->toBe(TraderStatus::Watched);
});

it('requires confirmation for the ignore action: mounting it alone never changes the trader status', function () {
    $this->actingAs(User::factory()->create());
    $trader = Trader::factory()->create(['status' => TraderStatus::Candidate]);

    Livewire::test(ListTraders::class)
        ->mountTableAction('markIgnored', $trader);

    expect(Trader::query()->findOrFail($trader->id)->status)->toBe(TraderStatus::Candidate);
});

it('changes a trader status to ignored after confirmation and notifies', function () {
    $this->actingAs(User::factory()->create());
    $trader = Trader::factory()->create(['status' => TraderStatus::Candidate]);

    Livewire::test(ListTraders::class)
        ->callTableAction('markIgnored', $trader)
        ->assertNotified('Trader status updated: Ignore');

    expect(Trader::query()->findOrFail($trader->id)->status)->toBe(TraderStatus::Ignored);
});

// --- Profile lookup action --------------------------------------------------

it('looks up an eToro profile that matches a local trader, enriches it, and links to the import run', function () {
    $this->actingAs(User::factory()->create());
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    Http::fake([TRADER_PROFILE_LOOKUP_URL => Http::response(traderProfileLookupPayload(username: 'trader_001'), 200)]);

    Livewire::test(ListTraders::class)
        ->callTableAction('lookupProfile', $trader)
        ->assertNotified('Profile synced');

    expect(Trader::query()->findOrFail($trader->id)->profile_gcid)->toBe('900001');

    $importRun = ImportRun::query()->where('type', 'profile')->latest('id')->firstOrFail();
    expect($importRun->success_count)->toBe(1);
});

it('a row lookup where the remote profile username does not match the row (profile_identity_mismatch) never mutates or creates any Trader', function () {
    $this->actingAs(User::factory()->create());
    // The row action always queries the RECORD's own username
    // ('trader_001'), so a row lookup can never reach a "no local match"
    // outcome by construction — the row IS the local match. Faking a
    // response whose username differs from the query is instead exactly
    // eToro-side identity mismatch handling: see LookupEtoroTraderProfile's
    // exact-username check. The Discover page's own lookup — which is not
    // tied to any existing row — is what actually proves the
    // Completed/remote-unmatched/no-create path.
    $trader = Trader::factory()->create(['username' => 'trader_001', 'copiers_count' => 111]);

    Http::fake([TRADER_PROFILE_LOOKUP_URL => Http::response(traderProfileLookupPayload(username: 'someone_else_entirely'), 200)]);

    Livewire::test(ListTraders::class)
        ->callTableAction('lookupProfile', $trader)
        ->assertNotified('Profile lookup did not complete');

    $fromDatabase = Trader::query()->findOrFail($trader->id);
    expect($fromDatabase->profile_gcid)->toBeNull()
        ->and($fromDatabase->copiers_count)->toBe(111)
        ->and(Trader::query()->count())->toBe(1);

    $importRun = ImportRun::query()->where('type', 'profile')->latest('id')->firstOrFail();
    expect($importRun->metadata['stop_reason'])->toBe('profile_identity_mismatch');
});

it('shows the static, sanitized "did not complete" notification on a request failure, and never leaks the raw response body', function () {
    $this->actingAs(User::factory()->create());
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    Http::fake([TRADER_PROFILE_LOOKUP_URL => Http::response(['error' => 'boom-SENTINEL'], 500)]);

    Livewire::test(ListTraders::class)
        ->callTableAction('lookupProfile', $trader);

    // A single read of the notification sink: Filament's own
    // assertNotified() also consumes it, so this must be the only read in
    // this test — both the title and body assertions come from it.
    $notifications = traderResourceTestSentNotifications();

    expect($notifications)->toHaveCount(1);

    $notification = $notifications->first();
    expect($notification->getTitle())->toBe('Profile lookup did not complete')
        ->and($notification->getBody())->toBe('The eToro profile lookup stopped before completing. See the linked import run for details.')
        ->and($notification->getBody())->not->toContain('boom-SENTINEL')
        ->and($notification->getTitle())->not->toContain('boom-SENTINEL');

    expect(Trader::query()->findOrFail($trader->id)->profile_gcid)->toBeNull();
});

it('never renders eToro credentials anywhere on the trader list page', function () {
    $this->actingAs(User::factory()->create());
    Trader::factory()->create();

    Livewire::test(ListTraders::class)
        ->assertDontSee('test-api-key-value-sentinel')
        ->assertDontSee('test-user-key-value-sentinel');
});
