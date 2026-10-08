<?php

use App\Application\DemoCopy\AdjustDemoCopy;
use App\Application\DemoCopy\CloseDemoCopy;
use App\Application\DemoCopy\DemoCopyLedger;
use App\Application\DemoCopy\DemoCopyRefused;
use App\Application\DemoCopy\PollDemoCopyOutcome;
use App\Application\DemoCopy\PreCheckDemoCopy;
use App\Application\DemoCopy\StartDemoCopy;
use App\Etoro\DemoCopyUnregisterType;
use App\Jobs\PollDemoCopyOutcomeJob;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const DEMO_COPY_ELIGIBILITY_URL = 'https://public-api.etoro.com/api/v2/trading/copy/demo/eligibility';
const DEMO_COPY_REGISTER_URL = 'https://public-api.etoro.com/api/v2/trading/copy/demo';
const DEMO_COPY_TOKEN = '3c0e5b1f-8d2a-4f6e-9b7c-1a2b3c4d5e6f';

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.allow_write' => false,
        'etoro.allow_demo_copy' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'demo-copy-api-key-sentinel',
        'etoro.user_key' => 'demo-copy-user-key-sentinel',
    ]);

    Http::preventStrayRequests();
    Queue::fake();

    $this->user = User::factory()->create();
    $this->trader = Trader::factory()->create(['external_cid' => '5551234', 'username' => 'investor-username']);
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

/**
 * @param  array<string, mixed>  $body
 */
function fakeEligibility(array $body = ['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => true], int $status = 200): void
{
    Http::fake(['*/copy/demo/eligibility' => Http::response($body, $status)]);
}

function acceptedPreCheck(Trader $trader, User $user, int $amountCents = 50_000, DemoCopyOperationType $intent = DemoCopyOperationType::Start): DemoCopyOperation
{
    fakeEligibility(['CID' => 4441234, 'parentCID' => (int) $trader->external_cid, 'isSuccess' => true]);

    return app(PreCheckDemoCopy::class)->handle($trader, $amountCents, $intent, $user->id);
}

function succeededCopy(Trader $trader, int $mirrorId = 424242): DemoCopyOperation
{
    return DemoCopyOperation::factory()->create([
        'trader_id' => $trader->id,
        'type' => DemoCopyOperationType::Start,
        'status' => DemoCopyOperationStatus::Succeeded,
        'mirror_id' => $mirrorId,
    ]);
}

/**
 * The documented close acknowledgment: the token echoes clientRequestID.
 */
function closeAcknowledgment(): Closure
{
    return fn (Request $request) => Http::response(['token' => $request['clientRequestID']], 200);
}

function registrationRequests(): int
{
    return collect(Http::recorded())
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && $pair[0]->url() === DEMO_COPY_REGISTER_URL)
        ->count();
}

it('records an accepted pre-check without the own-account CID', function () {
    $preCheck = acceptedPreCheck($this->trader, $this->user);

    expect($preCheck->type)->toBe(DemoCopyOperationType::PreCheck)
        ->and($preCheck->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($preCheck->amount_cents)->toBe(50_000)
        ->and($preCheck->parent_cid)->toBe(5551234)
        ->and($preCheck->user_id)->toBe($this->user->id)
        ->and($preCheck->request_id)->not->toBeNull()
        ->and($preCheck->http_status)->toBe(200)
        ->and($preCheck->response)->toBe(['parentCID' => 5551234, 'isSuccess' => true]);

    Http::assertSent(fn (Request $request) => $request->url() === DEMO_COPY_ELIGIBILITY_URL
        && $request->header('x-request-id') === [$preCheck->request_id]);
});

it('records a pre-check rejection with eToro\'s reason, and the rejected pre-check cannot start a copy', function () {
    fakeEligibility(['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => false, 'errorCode' => 42, 'errorMessage' => 'Copy limit reached']);

    $preCheck = app(PreCheckDemoCopy::class)->handle($this->trader, 50_000, DemoCopyOperationType::Start, $this->user->id);

    expect($preCheck->status)->toBe(DemoCopyOperationStatus::Rejected)
        ->and($preCheck->error_code)->toBe('42')
        ->and($preCheck->reason)->toBe('eToro: Copy limit reached');

    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true))
        ->toThrow(DemoCopyRefused::class, 'successful eToro pre-check is required');

    expect(registrationRequests())->toBe(0);
});

it('records a pre-check without a reason as rejected, never as allowed', function () {
    fakeEligibility(['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => false]);

    $preCheck = app(PreCheckDemoCopy::class)->handle($this->trader, 50_000, DemoCopyOperationType::Start, $this->user->id);

    expect($preCheck->status)->toBe(DemoCopyOperationStatus::Rejected)
        ->and($preCheck->reason)->toContain('no reason given');
});

it('records a 400 pre-check as rejected and a 500 as unknown', function (int $status, DemoCopyOperationStatus $expected) {
    fakeEligibility(['error' => 'Amount must not be zero'], $status);

    $preCheck = app(PreCheckDemoCopy::class)->handle($this->trader, 50_000, DemoCopyOperationType::Start, $this->user->id);

    expect($preCheck->status)->toBe($expected)
        ->and($preCheck->http_status)->toBe($status);
})->with([
    [400, DemoCopyOperationStatus::Rejected],
    [403, DemoCopyOperationStatus::Rejected],
    [500, DemoCopyOperationStatus::Unknown],
]);

it('refuses invalid amounts before sending or recording anything', function (int $amountCents, DemoCopyOperationType $intent) {
    Http::fake();

    if ($intent === DemoCopyOperationType::Adjust) {
        succeededCopy($this->trader);
    }

    expect(fn () => app(PreCheckDemoCopy::class)->handle($this->trader, $amountCents, $intent, $this->user->id))
        ->toThrow(DemoCopyRefused::class);

    Http::assertNothingSent();
    expect(DemoCopyOperation::where('type', DemoCopyOperationType::PreCheck)->count())->toBe(0);
})->with([
    'zero' => [0, DemoCopyOperationType::Start],
    'negative start' => [-100, DemoCopyOperationType::Start],
    'above $10M' => [1_000_000_001, DemoCopyOperationType::Start],
    'adjust zero' => [0, DemoCopyOperationType::Adjust],
    'adjust below -$10M' => [-1_000_000_001, DemoCopyOperationType::Adjust],
]);

it('accepts exactly $10,000,000', function () {
    $preCheck = acceptedPreCheck($this->trader, $this->user, 1_000_000_000);

    expect($preCheck->status)->toBe(DemoCopyOperationStatus::Accepted);
});

it('refuses everything while demo copy is disabled, without any request or audit row', function () {
    config(['etoro.allow_demo_copy' => false, 'etoro.allow_write' => true]);
    Http::fake();

    expect(fn () => app(PreCheckDemoCopy::class)->handle($this->trader, 50_000, DemoCopyOperationType::Start, $this->user->id))
        ->toThrow(DemoCopyRefused::class, 'ETORO_ALLOW_DEMO_COPY');

    Http::assertNothingSent();
    expect(DemoCopyOperation::count())->toBe(0);
});

it('refuses a start that was not explicitly confirmed', function () {
    $preCheck = acceptedPreCheck($this->trader, $this->user);

    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: false))
        ->toThrow(DemoCopyRefused::class, 'Explicit confirmation');

    expect(registrationRequests())->toBe(0);
});

it('runs the full flow: pre-check, start, poll (404 then success) — each step audited', function () {
    $preCheck = acceptedPreCheck($this->trader, $this->user);

    Http::fake(['*/copy/demo' => Http::response(['token' => '3c0e5b1f-8d2a-4f6e-9b7c-1a2b3c4d5e6f'], 200)]);
    $start = app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    expect($start->type)->toBe(DemoCopyOperationType::Start)
        ->and($start->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($start->parent_operation_id)->toBe($preCheck->id)
        ->and($start->amount_cents)->toBe(50_000)
        ->and($start->reference_id)->toMatch('/\Atl-[0-9a-z]{26}\z/')
        ->and($start->response)->toBe(['token' => '3c0e5b1f-8d2a-4f6e-9b7c-1a2b3c4d5e6f']);

    Http::assertSent(fn (Request $request) => $request->url() === DEMO_COPY_REGISTER_URL
        && $request->header('x-request-id') === [$start->request_id]
        && $request->data() === ['parentCID' => 5551234, 'amount' => 500, 'referenceID' => $start->reference_id]);
    Queue::assertPushed(PollDemoCopyOutcomeJob::class, fn (PollDemoCopyOutcomeJob $job) => $job->registration->is($start));

    Http::fake(['*/copy/demo/'.$start->reference_id => Http::sequence()
        ->push(['error' => 'No copy operation was found for the supplied reference id.'], 404)
        ->push(['referenceID' => $start->reference_id, 'isSuccess' => true, 'mirrorID' => 424242, 'parentCID' => 5551234, 'parentUsername' => 'investor-username'], 200),
    ]);

    $job = (new PollDemoCopyOutcomeJob($start))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased();
    expect($start->refresh()->status)->toBe(DemoCopyOperationStatus::Accepted);

    $job = (new PollDemoCopyOutcomeJob($start))->withFakeQueueInteractions();
    $job->job->attempts = 2;
    app()->call([$job, 'handle']);
    $job->assertNotReleased();

    $start->refresh();
    expect($start->status)->toBe(DemoCopyOperationStatus::Succeeded)
        ->and($start->mirror_id)->toBe(424242)
        ->and(app(DemoCopyLedger::class)->activeCopy($this->trader)?->is($start))->toBeTrue();

    $polls = DemoCopyOperation::where('type', DemoCopyOperationType::Poll)->orderBy('id')->get();
    expect($polls)->toHaveCount(2)
        ->and($polls->pluck('status')->all())->toBe([DemoCopyOperationStatus::Unknown, DemoCopyOperationStatus::Succeeded])
        ->and($polls->pluck('parent_operation_id')->unique()->all())->toBe([$start->id])
        ->and($polls->pluck('http_status')->all())->toBe([404, 200]);
});

it('marks a registration failed when eToro reports a failed outcome', function () {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-failed-ref',
    ]);
    Http::fake(['*' => Http::response(['referenceID' => 'tl-failed-ref', 'isSuccess' => false, 'errorMessageCode' => 623, 'failReason' => 'Insufficient funds'], 200)]);

    app(PollDemoCopyOutcome::class)->handle($start);

    $start->refresh();
    expect($start->status)->toBe(DemoCopyOperationStatus::Failed)
        ->and($start->error_code)->toBe('623')
        ->and($start->reason)->toContain('Insufficient funds')
        ->and($start->mirror_id)->toBeNull();
});

it('never treats a status answer for another referenceID or without mirrorID as success', function (array $body) {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-mine',
    ]);
    Http::fake(['*' => Http::response($body, 200)]);

    $poll = app(PollDemoCopyOutcome::class)->handle($start);

    expect($poll?->status)->toBe(DemoCopyOperationStatus::Unknown)
        ->and($start->refresh()->status)->toBe(DemoCopyOperationStatus::Accepted);
})->with([
    'other reference' => [['referenceID' => 'tl-other', 'isSuccess' => true, 'mirrorID' => 1]],
    'missing mirrorID' => [['referenceID' => 'tl-mine', 'isSuccess' => true]],
    'missing isSuccess' => [['referenceID' => 'tl-mine']],
]);

it('records unknown — not succeeded — when polling reaches its limit', function () {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-slow',
        'completed_at' => null,
    ]);
    Http::fake(['*' => Http::response(['error' => 'No copy operation was found for the supplied reference id.'], 404)]);

    $job = (new PollDemoCopyOutcomeJob($start))->withFakeQueueInteractions();
    $job->job->attempts = PollDemoCopyOutcomeJob::MAX_POLLS;
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($start->refresh()->status)->toBe(DemoCopyOperationStatus::Unknown)
        ->and($start->reason)->toContain('No terminal outcome after 10 status polls');
});

it('records unknown when the polling job fails', function () {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-crash',
    ]);

    (new PollDemoCopyOutcomeJob($start))->failed(new RuntimeException('worker died'));

    expect($start->refresh()->status)->toBe(DemoCopyOperationStatus::Unknown);
});

it('stops polling as unknown when demo copy is switched off meanwhile', function () {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-off',
    ]);
    config(['etoro.allow_demo_copy' => false]);
    Http::fake();

    $job = (new PollDemoCopyOutcomeJob($start))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    Http::assertNothingSent();
    expect($start->refresh()->status)->toBe(DemoCopyOperationStatus::Unknown);
});

it('never retries a start: a 5xx or a lost response is recorded as unknown and only polled', function (Closure $response) {
    $preCheck = acceptedPreCheck($this->trader, $this->user);
    Http::fake(['*/copy/demo' => $response]);

    $start = app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    expect($start->status)->toBe(DemoCopyOperationStatus::Unknown)
        ->and($start->reason)->not->toBeNull();
    Queue::assertPushed(PollDemoCopyOutcomeJob::class);
    expect(collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->url() === DEMO_COPY_REGISTER_URL)->count())->toBeLessThanOrEqual(1);
})->with([
    '503' => [fn () => fn () => Http::response(['error' => 'unavailable'], 503)],
    'timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
]);

it('records a start refused by eToro as rejected without polling', function () {
    $preCheck = acceptedPreCheck($this->trader, $this->user);
    Http::fake(['*/copy/demo' => Http::response(['errorCode' => 'InsufficientPermissions', 'errorMessage' => 'InsufficientPermissions'], 403)]);

    $start = app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    expect($start->status)->toBe(DemoCopyOperationStatus::Rejected)
        ->and($start->reason)->toBe('eToro HTTP 403: InsufficientPermissions');
    Queue::assertNotPushed(PollDemoCopyOutcomeJob::class);
});

it('redacts the configured eToro keys from an error body before auditing it', function (string $field) {
    $preCheck = acceptedPreCheck($this->trader, $this->user);
    Http::fake(['*/copy/demo' => Http::response([$field => 'Bad key demo-copy-api-key-sentinel (user demo-copy-user-key-sentinel)'], 401)]);

    $start = app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    expect($start->reason)->toBe('eToro HTTP 401: Bad key [redacted] (user [redacted])')
        ->and($start->response[$field])->toBe('Bad key [redacted] (user [redacted])')
        ->and(json_encode($start->getAttributes()))->not->toContain('sentinel');
})->with(['error', 'errorMessage']);

it('redacts a configured key sent alone as the pre-check errorMessage', function () {
    fakeEligibility(['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => false, 'errorMessage' => 'demo-copy-user-key-sentinel']);

    $operation = app(PreCheckDemoCopy::class)->handle($this->trader, 50_000, DemoCopyOperationType::Start, $this->user->id);

    expect($operation->reason)->toBe('eToro: [redacted]')
        ->and($operation->response['errorMessage'])->toBe('[redacted]');
});

it('redacts a configured key from a failed outcome reason', function () {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-failed-ref',
    ]);
    Http::fake(['*' => Http::response(['referenceID' => 'tl-failed-ref', 'isSuccess' => false, 'failReason' => 'key=demo-copy-api-key-sentinel'], 200)]);

    $poll = app(PollDemoCopyOutcome::class)->handle($start);

    expect($start->refresh()->reason)->toBe('eToro: operation failed — key=[redacted].')
        ->and($poll?->response['failReason'])->toBe('key=[redacted]');
});

it('uses a pre-check only once', function () {
    $preCheck = acceptedPreCheck($this->trader, $this->user);
    Http::fake(['*/copy/demo' => Http::response(['error' => 'Amount must not be zero'], 400)]);
    app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true))
        ->toThrow(DemoCopyRefused::class, 'already used');

    expect(registrationRequests())->toBe(1);
});

it('refuses an expired pre-check or one of another user', function (Closure $mutate, string $message) {
    $preCheck = acceptedPreCheck($this->trader, $this->user);
    $userId = $mutate($preCheck, $this->user);

    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $userId, confirmed: true))
        ->toThrow(DemoCopyRefused::class, $message);

    expect(registrationRequests())->toBe(0);
})->with([
    'expired' => [function (DemoCopyOperation $preCheck, User $user): int {
        Carbon::setTestNow(Carbon::now()->addMinutes(11));

        return $user->id;
    }, 'expired'],
    'other user' => [fn (DemoCopyOperation $preCheck, User $user): int => User::factory()->create()->id, 'another user'],
]);

it('refuses a new start while a registration of the trader awaits its outcome', function () {
    DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
    ]);
    $preCheck = acceptedPreCheck($this->trader, $this->user);

    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true))
        ->toThrow(DemoCopyRefused::class, 'still awaiting');
});

it('requires an explicit acknowledgment while an earlier registration has an unknown outcome', function () {
    DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Unknown,
    ]);
    $preCheck = acceptedPreCheck($this->trader, $this->user);

    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true))
        ->toThrow(DemoCopyRefused::class, 'unknown outcome');

    Http::fake(['*/copy/demo' => Http::response(['token' => DEMO_COPY_TOKEN], 200)]);
    $start = app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true, acknowledgeUnresolved: true);

    expect($start->status)->toBe(DemoCopyOperationStatus::Accepted);
});

it('refuses to start a copy that is already active and to adjust one that is not', function () {
    expect(fn () => app(PreCheckDemoCopy::class)->handle($this->trader, 10_000, DemoCopyOperationType::Adjust, $this->user->id))
        ->toThrow(DemoCopyRefused::class, 'No active demo copy');

    succeededCopy($this->trader);

    expect(fn () => app(PreCheckDemoCopy::class)->handle($this->trader, 10_000, DemoCopyOperationType::Start, $this->user->id))
        ->toThrow(DemoCopyRefused::class, 'already copied');
});

it('adjusts an active copy by removing funds through the same register endpoint', function () {
    succeededCopy($this->trader);
    $preCheck = acceptedPreCheck($this->trader, $this->user, -10_000, DemoCopyOperationType::Adjust);

    Http::fake(['*/copy/demo' => Http::response(['token' => DEMO_COPY_TOKEN], 200)]);
    $adjust = app(AdjustDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    expect($adjust->type)->toBe(DemoCopyOperationType::Adjust)
        ->and($adjust->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($adjust->mirror_id)->toBe(424242);
    Http::assertSent(fn (Request $request) => $request->url() === DEMO_COPY_REGISTER_URL && $request['amount'] === -100);
});

it('records an acknowledged close as requested — never as closed — so the copy stays active', function () {
    $copy = succeededCopy($this->trader);
    Http::fake(['*/copy/demo/close' => closeAcknowledgment()]);

    expect(fn () => app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: false))
        ->toThrow(DemoCopyRefused::class, 'Explicit confirmation');
    Http::assertNothingSent();

    $close = app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: true);
    $ledger = app(DemoCopyLedger::class);

    expect($close->type)->toBe(DemoCopyOperationType::Close)
        ->and($close->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($close->reason)->toContain('NOT confirmed')
        ->and($close->mirror_id)->toBe(424242)
        ->and($close->unregister_type)->toBe('Close')
        ->and($ledger->activeCopy($this->trader)?->is($copy))->toBeTrue()
        ->and($ledger->unconfirmedClose($this->trader)?->is($close))->toBeTrue();
    Http::assertSent(fn (Request $request) => $request['mirrorID'] === 424242 && $request['clientRequestID'] === $close->client_request_id);
});

it('records a close response that is not the documented token acknowledgment as unknown', function (Closure $response) {
    succeededCopy($this->trader);
    Http::fake(['*/copy/demo/close' => $response]);

    $close = app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: true);

    expect($close->status)->toBe(DemoCopyOperationStatus::Unknown)
        ->and(app(DemoCopyLedger::class)->unconfirmedClose($this->trader)?->is($close))->toBeTrue();
})->with([
    'non-uuid token' => [fn () => fn () => Http::response(['token' => 'ack'], 200)],
    'token of another request' => [fn () => fn () => Http::response(['token' => DEMO_COPY_TOKEN], 200)],
    'missing token' => [fn () => fn () => Http::response(['ok' => true], 200)],
    'token not a string' => [fn () => fn () => Http::response(['token' => 123], 200)],
    'empty body' => [fn () => fn () => Http::response('', 200)],
    'undocumented 202' => [fn (): Closure => fn (Request $request) => Http::response(['token' => $request['clientRequestID']], 202)],
    'undocumented 204' => [fn () => fn () => Http::response('', 204)],
]);

it('reuses the clientRequestID when a close that may have been processed is sent again', function (Closure $firstResponse) {
    succeededCopy($this->trader);
    $calls = 0;
    Http::fake(['*/copy/demo/close' => function (Request $request) use (&$calls, $firstResponse) {
        return ++$calls === 1 ? $firstResponse() : closeAcknowledgment()($request);
    }]);

    $first = app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: true);
    $second = app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: true);

    expect($first->status)->toBe(DemoCopyOperationStatus::Unknown)
        ->and($second->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($second->client_request_id)->toBe($first->client_request_id)
        ->and($second->request_id)->not->toBe($first->request_id);
})->with([
    'unknown (503)' => [fn () => fn () => Http::response(['error' => 'unavailable'], 503)],
    'unknown (malformed 200)' => [fn () => fn () => Http::response(['token' => 'ack'], 200)],
]);

it('reuses the clientRequestID when an acknowledged (unconfirmed) close is sent again', function () {
    succeededCopy($this->trader);
    Http::fake(['*/copy/demo/close' => closeAcknowledgment()]);

    $first = app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: true);
    $second = app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: true);

    expect($first->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($second->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($second->client_request_id)->toBe($first->client_request_id);
    Http::assertSentCount(2);
});

it('after a close request, refuses adjust and requires an explicit acknowledgment before a new start', function () {
    succeededCopy($this->trader);
    Http::fake(['*/copy/demo/close' => closeAcknowledgment()]);
    app(CloseDemoCopy::class)->handle($this->trader, DemoCopyUnregisterType::Close, $this->user->id, confirmed: true);

    expect(fn () => app(PreCheckDemoCopy::class)->handle($this->trader, 10_000, DemoCopyOperationType::Adjust, $this->user->id))
        ->toThrow(DemoCopyRefused::class, 'adjusting is not available');

    $preCheck = acceptedPreCheck($this->trader, $this->user);
    expect($preCheck->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($preCheck->mirror_id)->toBeNull();

    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true))
        ->toThrow(DemoCopyRefused::class, 'may treat a new start as adding funds');
    expect(registrationRequests())->toBe(0);

    Http::fake(['*/copy/demo' => Http::response(['token' => DEMO_COPY_TOKEN], 200)]);
    $start = app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true, acknowledgeUnconfirmedClose: true);

    expect($start->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($start->mirror_id)->toBeNull()
        ->and(registrationRequests())->toBe(1);
});

it('does not let the unconfirmed-close acknowledgment bypass the already-copied rule', function () {
    succeededCopy($this->trader);

    expect(fn () => app(PreCheckDemoCopy::class)->handle($this->trader, 10_000, DemoCopyOperationType::Start, $this->user->id))
        ->toThrow(DemoCopyRefused::class, 'already copied');
});

it('never treats a pre-check body outside the contract as allowed, and it cannot start a copy', function (array $body, int $status) {
    fakeEligibility($body, $status);

    $preCheck = app(PreCheckDemoCopy::class)->handle($this->trader, 50_000, DemoCopyOperationType::Start, $this->user->id);

    expect($preCheck->status)->toBe(DemoCopyOperationStatus::Unknown);
    expect(fn () => app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true))
        ->toThrow(DemoCopyRefused::class, 'successful eToro pre-check is required');
    expect(registrationRequests())->toBe(0);
})->with([
    'missing parentCID' => [['CID' => 4441234, 'isSuccess' => true], 200],
    'null parentCID' => [['CID' => 4441234, 'parentCID' => null, 'isSuccess' => true], 200],
    'other parentCID' => [['CID' => 4441234, 'parentCID' => 5551235, 'isSuccess' => true], 200],
    'string parentCID' => [['CID' => 4441234, 'parentCID' => '5551234', 'isSuccess' => true], 200],
    'missing CID' => [['parentCID' => 5551234, 'isSuccess' => true], 200],
    'string CID' => [['CID' => '4441234', 'parentCID' => 5551234, 'isSuccess' => true], 200],
    'zero CID' => [['CID' => 0, 'parentCID' => 5551234, 'isSuccess' => true], 200],
    'missing isSuccess' => [['CID' => 4441234, 'parentCID' => 5551234], 200],
    'string isSuccess' => [['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => 'true'], 200],
    'string errorCode' => [['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => true, 'errorCode' => '42'], 200],
    'non-string errorMessage' => [['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => true, 'errorMessage' => ['x']], 200],
    'undocumented 201' => [['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => true], 201],
    'list body' => [[['CID' => 4441234, 'parentCID' => 5551234, 'isSuccess' => true]], 200],
]);

it('records a start acknowledgment outside the contract as unknown and only polls it', function (Closure $response) {
    $preCheck = acceptedPreCheck($this->trader, $this->user);
    Http::fake(['*/copy/demo' => $response]);

    $start = app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    expect($start->status)->toBe(DemoCopyOperationStatus::Unknown)
        ->and($start->reason)->toContain('Unexpected eToro acknowledgment');
    Queue::assertPushed(PollDemoCopyOutcomeJob::class);
    expect(registrationRequests())->toBe(1);
})->with([
    'missing token' => [fn () => fn () => Http::response(['ok' => true], 200)],
    'non-uuid token' => [fn () => fn () => Http::response(['token' => 't'], 200)],
    'empty body' => [fn () => fn () => Http::response('', 200)],
    'undocumented 202' => [fn () => fn () => Http::response(['token' => DEMO_COPY_TOKEN], 202)],
]);

it('never treats a status body outside the contract as a terminal outcome', function (array $body, int $status) {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-mine',
        'parent_cid' => 5551234,
    ]);
    Http::fake(['*' => Http::response($body, $status)]);

    $poll = app(PollDemoCopyOutcome::class)->handle($start);

    expect($poll?->status)->toBe(DemoCopyOperationStatus::Unknown)
        ->and($start->refresh()->status)->toBe(DemoCopyOperationStatus::Accepted)
        ->and($start->mirror_id)->toBeNull();
})->with([
    'other parentCID' => [['referenceID' => 'tl-mine', 'isSuccess' => true, 'mirrorID' => 1, 'parentCID' => 1, 'parentUsername' => 'x'], 200],
    'parentCID without parentUsername' => [['referenceID' => 'tl-mine', 'isSuccess' => true, 'mirrorID' => 1, 'parentCID' => 5551234], 200],
    'parentUsername without parentCID' => [['referenceID' => 'tl-mine', 'isSuccess' => true, 'mirrorID' => 1, 'parentUsername' => 'x'], 200],
    'string mirrorID' => [['referenceID' => 'tl-mine', 'isSuccess' => true, 'mirrorID' => '1'], 200],
    'mirrorID above int32' => [['referenceID' => 'tl-mine', 'isSuccess' => true, 'mirrorID' => 2_147_483_648], 200],
    'mirrorID on a failure' => [['referenceID' => 'tl-mine', 'isSuccess' => false, 'mirrorID' => 1], 200],
    'string isSuccess' => [['referenceID' => 'tl-mine', 'isSuccess' => 'true', 'mirrorID' => 1], 200],
    'missing referenceID' => [['isSuccess' => true, 'mirrorID' => 1], 200],
    'string errorMessageCode' => [['referenceID' => 'tl-mine', 'isSuccess' => false, 'errorMessageCode' => '623'], 200],
    'non-string failReason' => [['referenceID' => 'tl-mine', 'isSuccess' => false, 'failReason' => 1], 200],
    'undocumented 201' => [['referenceID' => 'tl-mine', 'isSuccess' => true, 'mirrorID' => 1], 201],
]);

it('accepts a successful status that echoes the copied trader', function () {
    $start = DemoCopyOperation::factory()->create([
        'trader_id' => $this->trader->id,
        'status' => DemoCopyOperationStatus::Accepted,
        'reference_id' => 'tl-mine',
        'parent_cid' => 5551234,
    ]);
    Http::fake(['*' => Http::response(['referenceID' => 'tl-mine', 'isSuccess' => true, 'mirrorID' => 7, 'parentCID' => 5551234, 'parentUsername' => 'investor-username'], 200)]);

    app(PollDemoCopyOutcome::class)->handle($start);

    expect($start->refresh()->status)->toBe(DemoCopyOperationStatus::Succeeded)
        ->and($start->mirror_id)->toBe(7);
});

it('never stores credentials in the audit log', function () {
    $preCheck = acceptedPreCheck($this->trader, $this->user);
    Http::fake(['*/copy/demo' => Http::response(['token' => DEMO_COPY_TOKEN, 'echo' => 'demo-copy-api-key-sentinel'], 200)]);
    app(StartDemoCopy::class)->handle($preCheck, $this->user->id, confirmed: true);

    $stored = DemoCopyOperation::all()->toJson();

    expect($stored)->not->toContain('demo-copy-api-key-sentinel')
        ->not->toContain('demo-copy-user-key-sentinel');
});
