<?php

use App\Filament\Resources\DemoCopyOperations\Pages\ListDemoCopyOperations;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Jobs\PollDemoCopyOutcomeJob;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    config([
        'etoro.enabled' => true,
        'etoro.allow_write' => false,
        'etoro.allow_demo_copy' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value-sentinel',
        'etoro.user_key' => 'test-user-key-value-sentinel',
    ]);
    Http::preventStrayRequests();
    Queue::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->trader = Trader::factory()->create(['external_cid' => '5551234', 'username' => 'investor-username']);
});

function fakeDemoCopyApi(): void
{
    Http::fake([
        '*/copy/demo/eligibility' => Http::response(['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => true], 200),
        '*/copy/demo' => Http::response(['token' => '3c0e5b1f-8d2a-4f6e-9b7c-1a2b3c4d5e6f'], 200),
        '*/copy/demo/close' => fn (Request $request) => Http::response(['token' => $request['clientRequestID']], 200),
    ]);
}

it('shows the demo copy actions disabled with the reason while the flag is off, and cannot mount the confirmation', function () {
    config(['etoro.allow_demo_copy' => false]);
    Http::fake();

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->assertActionVisible('copyOnDemo')
        ->assertActionDisabled('copyOnDemo')
        ->assertActionDisabled('adjustDemoCopy')
        ->assertActionDisabled('closeDemoCopy')
        ->assertActionHidden('confirmDemoCopy')
        ->assertSee('ETORO_ALLOW_DEMO_COPY');

    Http::assertNothingSent();
});

it('enables „Copy on demo“ when the flag is on; adjust and close need an active copy', function () {
    Http::fake();

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->assertActionEnabled('copyOnDemo')
        ->assertActionDisabled('adjustDemoCopy')
        ->assertActionDisabled('closeDemoCopy');

    Http::assertNothingSent();
});

it('validates the amount before any request', function (string $amount) {
    Http::fake();

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->callAction('copyOnDemo', data: ['amount' => $amount])
        ->assertHasActionErrors(['amount']);

    Http::assertNothingSent();
    expect(DemoCopyOperation::count())->toBe(0);
})->with(['', '0', '-100', '10000000.01', '12.345', 'abc']);

it('runs the pre-check, then requires the explicit DEMO confirmation before starting', function () {
    fakeDemoCopyApi();

    $page = Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->callAction('copyOnDemo', data: ['amount' => '500'])
        ->assertHasNoActionErrors()
        ->assertActionMounted('confirmDemoCopy')
        ->assertMountedActionModalSee(['DEMO account — virtual money', 'investor-username', '$500.00', 'allowed']);

    $preCheck = DemoCopyOperation::sole();
    expect($preCheck->type)->toBe(DemoCopyOperationType::PreCheck)
        ->and($preCheck->status)->toBe(DemoCopyOperationStatus::Accepted);
    Http::assertSentCount(1);

    // Without the confirmation checkbox nothing is sent.
    $page->callMountedAction()
        ->assertHasActionErrors(['confirm_demo']);
    Http::assertSentCount(1);

    $page->setActionData(['confirm_demo' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $start = DemoCopyOperation::where('type', DemoCopyOperationType::Start)->sole();
    expect($start->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($start->parent_operation_id)->toBe($preCheck->id)
        ->and($start->user_id)->toBe($this->user->id);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://public-api.etoro.com/api/v2/trading/copy/demo');
    Queue::assertPushed(PollDemoCopyOutcomeJob::class);
});

it('stops after a rejected pre-check without opening the confirmation', function () {
    Http::fake(['*/copy/demo/eligibility' => Http::response(['CID' => 1, 'parentCID' => 5551234, 'isSuccess' => false, 'errorMessage' => 'Copy limit reached'], 200)]);

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->callAction('copyOnDemo', data: ['amount' => '500'])
        ->assertActionNotMounted('confirmDemoCopy')
        ->assertNotified('eToro pre-check: not allowed — nothing was copied');

    Http::assertSentCount(1);
    expect(DemoCopyOperation::where('type', DemoCopyOperationType::Start)->count())->toBe(0);
});

it('shows errorCode 972 and its labelled interpretation in the pre-check notification', function () {
    Http::fake(['*/copy/demo/eligibility' => Http::response(['CID' => 1, 'parentCID' => 5551234, 'isSuccess' => false, 'errorCode' => 972], 200)]);

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->callAction('copyOnDemo', data: ['amount' => '500'])
        ->assertActionNotMounted('confirmDemoCopy')
        ->assertNotified(
            Notification::make()
                ->title('eToro pre-check: not allowed — nothing was copied')
                ->body(DemoCopyOperation::where('type', DemoCopyOperationType::PreCheck)->sole()->reason.' (status: rejected)')
                ->danger()
                ->persistent(),
        );

    expect(DemoCopyOperation::where('type', DemoCopyOperationType::PreCheck)->sole()->reason)
        ->toStartWith('eToro refused the copy (errorCode 972). ');
});

it('does not accept a pre-check of another trader through the confirmation arguments', function () {
    fakeDemoCopyApi();
    $otherTrader = Trader::factory()->create(['external_cid' => '7770001']);
    $foreignPreCheck = DemoCopyOperation::factory()->create([
        'trader_id' => $otherTrader->id,
        'user_id' => $this->user->id,
        'type' => DemoCopyOperationType::PreCheck,
        'status' => DemoCopyOperationStatus::Accepted,
        'parent_cid' => 7770001,
        'reference_id' => null,
    ]);

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->mountAction('confirmDemoCopy', ['preCheck' => $foreignPreCheck->id])
        ->setActionData(['confirm_demo' => true])
        ->callMountedAction()
        ->assertNotified('Nothing was sent to eToro');

    Http::assertNothingSent();
});

it('closes an active copy only after the DEMO confirmation', function () {
    fakeDemoCopyApi();
    DemoCopyOperation::factory()->create(['trader_id' => $this->trader->id, 'mirror_id' => 424242]);

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->assertActionEnabled('closeDemoCopy')
        ->assertActionDisabled('copyOnDemo')
        ->callAction('closeDemoCopy', data: ['unregister_type' => 'Close'])
        ->assertHasActionErrors(['confirm_demo']);

    Http::assertNothingSent();

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->callAction('closeDemoCopy', data: ['unregister_type' => 'Detach', 'confirm_demo' => true])
        ->assertHasNoActionErrors();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://public-api.etoro.com/api/v2/trading/copy/demo/close'
        && $request['unregisterType'] === 'Detach' && $request['mirrorID'] === 424242);
});

it('after a close request, shows it as not confirmed, disables adjust and makes a new start warn and require the extra acknowledgment', function () {
    fakeDemoCopyApi();
    DemoCopyOperation::factory()->create(['trader_id' => $this->trader->id, 'mirror_id' => 424242]);

    Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->callAction('closeDemoCopy', data: ['unregister_type' => 'Close', 'confirm_demo' => true])
        ->assertNotified('Close demo copy: requested — not confirmed');

    $page = Livewire::test(ViewTrader::class, ['record' => $this->trader->id])
        ->assertActionEnabled('copyOnDemo')
        ->assertActionDisabled('adjustDemoCopy')
        ->assertActionEnabled('closeDemoCopy')
        ->mountAction('copyOnDemo')
        ->assertMountedActionModalSee('was requested but is NOT confirmed')
        ->setActionData(['amount' => '500'])
        ->callMountedAction()
        ->assertActionMounted('confirmDemoCopy')
        ->assertMountedActionModalSee('may treat this start as adding funds');

    $page->setActionData(['confirm_demo' => true])
        ->callMountedAction()
        ->assertHasActionErrors(['acknowledge_unconfirmed_close']);
    expect(DemoCopyOperation::where('type', DemoCopyOperationType::Start)->where('status', '!=', DemoCopyOperationStatus::Succeeded)->count())->toBe(0);
    Http::assertSentCount(2);

    $page->setActionData(['confirm_demo' => true, 'acknowledge_unconfirmed_close' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DemoCopyOperation::where('type', DemoCopyOperationType::Start)->latest('id')->first()?->status)->toBe(DemoCopyOperationStatus::Accepted);
    Http::assertSentCount(3);

    Livewire::test(ListDemoCopyOperations::class)->assertSee('Requested — not confirmed');
});

it('lists the audit log and checks an outcome on demand', function () {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'trader_username' => 'investor-username',
        'status' => DemoCopyOperationStatus::Unknown,
        'reference_id' => 'tl-check',
        'reason' => 'No response from eToro (request_timeout)',
    ]);
    Http::fake(['*/copy/demo/tl-check' => Http::response(['referenceID' => 'tl-check', 'isSuccess' => true, 'mirrorID' => 99], 200)]);

    Livewire::test(ListDemoCopyOperations::class)
        ->assertCanSeeTableRecords([$start])
        ->assertSee('No response from eToro (request_timeout)')
        ->callTableAction('checkOutcome', $start);

    expect($start->refresh()->status)->toBe(DemoCopyOperationStatus::Succeeded)
        ->and($start->mirror_id)->toBe(99);
});

it('renders the audit log without any HTTP call', function () {
    Http::fake();
    DemoCopyOperation::factory()->count(3)->create();

    Livewire::test(ListDemoCopyOperations::class)->assertSuccessful();

    Http::assertNothingSent();
});
