<?php

use App\Etoro\Data\AccountMirror;
use App\Etoro\Data\AccountPosition;
use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\AccountPnlMapper;

/**
 * @return array<string, mixed>
 */
function accountPnlFixture(string $name = 'account-pnl.json'): array
{
    return json_decode(file_get_contents(__DIR__.'/../../../Fixtures/Etoro/'.$name), associative: true, flags: JSON_THROW_ON_ERROR);
}

function accountPnlMappingError(array $payload): EtoroMappingException
{
    try {
        (new AccountPnlMapper)->map($payload);
    } catch (EtoroMappingException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an EtoroMappingException.');
}

it('maps an empty account (the shape observed live) into exact cents with no positions or copies', function () {
    $pnl = (new AccountPnlMapper)->map(accountPnlFixture('account-pnl-empty.json'));

    expect($pnl->creditCents)->toBe(9_876_543)
        ->and($pnl->unrealizedPnlCents)->toBe(0)
        ->and($pnl->positions)->toBe([])
        ->and($pnl->mirrors)->toBe([])
        ->and($pnl->pendingOrderCount)->toBe(0)
        ->and($pnl->ordersAmountCents)->toBe(0)
        ->and($pnl->manualOrdersForOpenAmountCents)->toBe(0)
        ->and($pnl->manualOrdersForOpenExternalCostsCents)->toBe(0)
        ->and($pnl->bonusCreditCents)->toBe(0)
        ->and($pnl->accountCurrencyId)->toBe(1)
        ->and($pnl->unmodeledFields)->toBe([]);
});

it('maps a full account: manual positions, a copy with its positions, and order sums', function () {
    $pnl = (new AccountPnlMapper)->map(accountPnlFixture());

    expect($pnl->creditCents)->toBe(500_025)
        ->and($pnl->unrealizedPnlCents)->toBe(18_750)
        ->and($pnl->positions)->toHaveCount(2)
        ->and($pnl->mirrors)->toHaveCount(1)
        ->and($pnl->pendingOrderCount)->toBe(3)
        ->and($pnl->ordersAmountCents)->toBe(25_000)
        ->and($pnl->manualOrdersForOpenAmountCents)->toBe(10_000)
        ->and($pnl->manualOrdersForOpenExternalCostsCents)->toBe(150)
        ->and($pnl->mirrorPositionCount())->toBe(2);

    $manual = $pnl->positions[0];
    expect($manual)->toBeInstanceOf(AccountPosition::class)
        ->and($manual->positionId)->toBe('100001')
        ->and($manual->instrumentId)->toBe('500001')
        ->and($manual->isBuy)->toBeTrue()
        ->and($manual->amountCents)->toBe(100_000)
        ->and($manual->mirrorId)->toBeNull()
        ->and($manual->parentPositionId)->toBeNull()
        ->and($manual->units)->toBe('5.4839000000')
        ->and($manual->openRate)->toBe('182.3500000000')
        ->and($manual->openedAt?->format(DATE_ATOM))->toBe('2026-09-01T10:00:00+00:00')
        ->and($manual->leverage)->toBe(1)
        ->and($manual->pnlCents)->toBe(12_040);

    expect($pnl->positions[1]->isBuy)->toBeFalse()
        ->and($pnl->positions[1]->amountCents)->toBe(50_050)
        ->and($pnl->positions[1]->pnlCents)->toBe(-2_015)
        ->and($pnl->positions[1]->openedAt?->format('Y-m-d H:i:s.u'))->toBe('2026-09-15 08:30:00.123000');

    $mirror = $pnl->mirrors[0];
    expect($mirror)->toBeInstanceOf(AccountMirror::class)
        ->and($mirror->mirrorId)->toBe('7001')
        ->and($mirror->parentCid)->toBe('5551234')
        ->and($mirror->parentUsername)->toBe('trader_001')
        ->and($mirror->initialInvestmentCents)->toBe(200_000)
        ->and($mirror->depositSummaryCents)->toBe(50_000)
        ->and($mirror->withdrawalSummaryCents)->toBe(0)
        ->and($mirror->availableAmountCents)->toBe(30_000)
        ->and($mirror->closedPositionsNetProfitCents)->toBe(5_000)
        ->and($mirror->isPaused)->toBeFalse()
        ->and($mirror->statusId)->toBe(0)
        ->and($mirror->startedAt?->format(DATE_ATOM))->toBe('2026-08-01T12:00:00+00:00')
        ->and($mirror->positions)->toHaveCount(2)
        ->and($mirror->positions[0]->mirrorId)->toBe('7001')
        ->and($mirror->positions[0]->parentPositionId)->toBe('300001')
        ->and($mirror->positions[0]->initialAmountCents)->toBe(115_000)
        ->and($mirror->positions[1]->pnlCents)->toBe(-205);
});

it('reports unknown clientPortfolio keys by name only and ignores unknown nested keys', function () {
    $payload = accountPnlFixture();
    $payload['clientPortfolio']['positions'][0]['brandNewField'] = ['secret' => 'value'];
    $payload['clientPortfolio']['weird key!'] = 'x';

    $pnl = (new AccountPnlMapper)->map($payload);

    expect($pnl->unmodeledFields)->toBe(['someFutureField', 'weirdkey'])
        ->and($pnl->positions)->toHaveCount(2);
});

it('accepts the example spelling of the documented keys and a flat pnL', function () {
    $payload = accountPnlFixture('account-pnl-empty.json');
    $payload['clientPortfolio']['positions'] = [[
        'positionId' => 1, 'instrumentId' => 2, 'isBuy' => true, 'amount' => 10.5,
        'mirrorId' => 0, 'parentPositionId' => 0, 'pnL' => 1.25,
    ]];
    $payload['clientPortfolio']['mirrors'] = [[
        'mirrorId' => 3, 'parentCid' => 4, 'mirrorStatusId' => 1, 'positions' => [],
    ]];

    $pnl = (new AccountPnlMapper)->map($payload);

    expect($pnl->positions[0]->positionId)->toBe('1')
        ->and($pnl->positions[0]->pnlCents)->toBe(125)
        ->and($pnl->positions[0]->mirrorId)->toBeNull()
        ->and($pnl->mirrors[0]->parentCid)->toBe('4')
        ->and($pnl->mirrors[0]->statusId)->toBe(1)
        ->and($pnl->mirrors[0]->parentUsername)->toBeNull()
        ->and($pnl->mirrors[0]->availableAmountCents)->toBeNull();
});

it('rejects two spellings of the same key with different values', function () {
    $payload = accountPnlFixture();
    $payload['clientPortfolio']['positions'][0]['positionId'] = 999;

    $exception = accountPnlMappingError($payload);

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue)
        ->and($exception->fieldPath)->toBe('clientPortfolio.positions[0].positionID');
});

it('fails instead of guessing when the nested and the flat position P&L disagree', function (Closure $mutate, string $fieldPath) {
    $payload = accountPnlFixture();
    $mutate($payload);

    $exception = accountPnlMappingError($payload);

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue)
        ->and($exception->fieldPath)->toBe($fieldPath)
        ->and($exception->getMessage())->not->toContain('99.99')
        ->and($exception->getMessage())->not->toContain('120.4');
})->with([
    'position P&L' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['pnL'] = 99.99;
    }, 'clientPortfolio.positions[0].unrealizedPnL.pnL'],
    'pending order mirror id' => [function (array &$payload) {
        $payload['clientPortfolio']['ordersForOpen'][0]['mirrorID'] = 1;
    }, 'clientPortfolio.ordersForOpen[0].mirrorID'],
    'pending order mirror id, reversed' => [function (array &$payload) {
        $payload['clientPortfolio']['ordersForOpen'][0]['mirrorId'] = 1;
        $payload['clientPortfolio']['ordersForOpen'][0]['mirrorID'] = 0;
    }, 'clientPortfolio.ordersForOpen[0].mirrorID'],
]);

it('accepts the nested and the flat position P&L, and both order mirror id spellings, when they agree', function () {
    $payload = accountPnlFixture();
    $payload['clientPortfolio']['positions'][0]['pnL'] = 120.4;
    $payload['clientPortfolio']['ordersForOpen'][0]['mirrorID'] = 0;

    $pnl = (new AccountPnlMapper)->map($payload);

    expect($pnl->positions[0]->pnlCents)->toBe(12_040)
        ->and($pnl->manualOrdersForOpenAmountCents)->toBe(10_000)
        ->and($pnl->manualOrdersForOpenExternalCostsCents)->toBe(150);
});

it('rejects an explicit null next to a non-null spelling, in either order, without echoing the value', function (Closure $mutate, string $fieldPath) {
    $payload = accountPnlFixture();
    $mutate($payload);

    $exception = accountPnlMappingError($payload);

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue)
        ->and($exception->fieldPath)->toBe($fieldPath)
        ->and($exception->getMessage())->not->toContain('12.3')
        ->and($exception->getMessage())->not->toContain('424242');
})->with([
    'position mirrorID null, mirrorId set' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['mirrorID'] = null;
        $payload['clientPortfolio']['positions'][0]['mirrorId'] = 424242;
    }, 'clientPortfolio.positions[0].mirrorID'],
    'position mirrorID set, mirrorId null' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['mirrorID'] = 424242;
        $payload['clientPortfolio']['positions'][0]['mirrorId'] = null;
    }, 'clientPortfolio.positions[0].mirrorID'],
    'nested pnL null, flat pnL set' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['unrealizedPnL']['pnL'] = null;
        $payload['clientPortfolio']['positions'][0]['pnL'] = 12.3;
    }, 'clientPortfolio.positions[0].unrealizedPnL.pnL'],
    'nested pnL set, flat pnL null' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['unrealizedPnL']['pnL'] = 12.3;
        $payload['clientPortfolio']['positions'][0]['pnL'] = null;
    }, 'clientPortfolio.positions[0].unrealizedPnL.pnL'],
    'null unrealizedPnL object, flat pnL set' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['unrealizedPnL'] = null;
        $payload['clientPortfolio']['positions'][0]['pnL'] = 12.3;
    }, 'clientPortfolio.positions[0].unrealizedPnL.pnL'],
    'order mirrorID null, mirrorId set' => [function (array &$payload) {
        $payload['clientPortfolio']['ordersForOpen'][0]['mirrorID'] = null;
    }, 'clientPortfolio.ordersForOpen[0].mirrorID'],
    'order mirrorID set, mirrorId null' => [function (array &$payload) {
        $payload['clientPortfolio']['ordersForOpen'][0]['mirrorID'] = 0;
        $payload['clientPortfolio']['ordersForOpen'][0]['mirrorId'] = null;
    }, 'clientPortfolio.ordersForOpen[0].mirrorID'],
    'mirror parentCID null, parentCid set' => [function (array &$payload) {
        $payload['clientPortfolio']['mirrors'][0]['parentCID'] = null;
        $payload['clientPortfolio']['mirrors'][0]['parentCid'] = 424242;
    }, 'clientPortfolio.mirrors[0].parentCID'],
    'sentinel mirror id 0 next to a real id' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['mirrorID'] = 0;
        $payload['clientPortfolio']['positions'][0]['mirrorId'] = '424242';
    }, 'clientPortfolio.positions[0].mirrorID'],
    'order mirror id 0 next to "0" (non-int is unusable there)' => [function (array &$payload) {
        $payload['clientPortfolio']['ordersForOpen'][0]['mirrorID'] = '0';
    }, 'clientPortfolio.ordersForOpen[0].mirrorID'],
]);

it('accepts two spellings whose canonical values agree', function () {
    $payload = accountPnlFixture();
    $position = &$payload['clientPortfolio']['positions'][0];
    $position['mirrorID'] = 0;
    $position['mirrorId'] = '0';
    $position['positionId'] = '100001';
    $position['instrumentId'] = ' 500001 ';
    $position['unrealizedPnL']['pnL'] = 12.3;
    $position['pnL'] = 12.30;
    $mirrorPosition = &$payload['clientPortfolio']['mirrors'][0]['positions'][0];
    $mirrorPosition['mirrorId'] = '7001';
    $mirrorPosition['parentPositionId'] = '300001';
    $payload['clientPortfolio']['positions'][1]['unrealizedPnL']['pnL'] = 12;
    $payload['clientPortfolio']['positions'][1]['pnL'] = 12.0;
    $payload['clientPortfolio']['mirrors'][0]['mirrorId'] = '7001';
    unset($position, $mirrorPosition);

    $pnl = (new AccountPnlMapper)->map($payload);

    expect($pnl->positions[0]->mirrorId)->toBeNull()
        ->and($pnl->positions[0]->positionId)->toBe('100001')
        ->and($pnl->positions[0]->instrumentId)->toBe('500001')
        ->and($pnl->positions[0]->pnlCents)->toBe(1_230)
        ->and($pnl->positions[1]->pnlCents)->toBe(1_200)
        ->and($pnl->mirrors[0]->mirrorId)->toBe('7001')
        ->and($pnl->mirrors[0]->positions[0]->mirrorId)->toBe('7001')
        ->and($pnl->mirrors[0]->positions[0]->parentPositionId)->toBe('300001');
});

it('treats two explicit null spellings like a single null under the field rules', function () {
    $payload = accountPnlFixture();
    $payload['clientPortfolio']['positions'][0]['mirrorID'] = null;
    $payload['clientPortfolio']['positions'][0]['mirrorId'] = null;
    $payload['clientPortfolio']['positions'][0]['unrealizedPnL']['pnL'] = null;
    $payload['clientPortfolio']['positions'][0]['pnL'] = null;

    $pnl = (new AccountPnlMapper)->map($payload);

    expect($pnl->positions[0]->mirrorId)->toBeNull()
        ->and($pnl->positions[0]->pnlCents)->toBeNull();

    $payload['clientPortfolio']['positions'][0]['positionID'] = null;
    $payload['clientPortfolio']['positions'][0]['positionId'] = null;

    $exception = accountPnlMappingError($payload);

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField)
        ->and($exception->fieldPath)->toBe('clientPortfolio.positions[0].positionID');
});

it('keeps a missing optional position P&L and mirror positions as unknown, never zero', function () {
    $payload = accountPnlFixture();
    unset($payload['clientPortfolio']['positions'][0]['unrealizedPnL'], $payload['clientPortfolio']['mirrors'][0]['positions']);

    $pnl = (new AccountPnlMapper)->map($payload);

    expect($pnl->positions[0]->pnlCents)->toBeNull()
        ->and($pnl->mirrors[0]->positions)->toBeNull()
        ->and($pnl->mirrorPositionCount())->toBe(0);
});

it('makes an order sum unknown when an order lacks the number, and absent order lists unknown', function () {
    $payload = accountPnlFixture();
    unset($payload['clientPortfolio']['ordersForOpen'][0]['totalExternalCosts'], $payload['clientPortfolio']['orders']);

    $pnl = (new AccountPnlMapper)->map($payload);

    expect($pnl->ordersAmountCents)->toBeNull()
        ->and($pnl->manualOrdersForOpenAmountCents)->toBe(10_000)
        ->and($pnl->manualOrdersForOpenExternalCostsCents)->toBeNull()
        ->and($pnl->pendingOrderCount)->toBe(2);
});

it('fails with a structural diagnosis when a required field is missing', function (Closure $mutate, string $fieldPath) {
    $payload = accountPnlFixture();
    $mutate($payload);

    $exception = accountPnlMappingError($payload);

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField)
        ->and($exception->mapper)->toBe('AccountPnlMapper')
        ->and($exception->fieldPath)->toBe($fieldPath)
        ->and($exception->getMessage())->not->toContain('5000.25');
})->with([
    'clientPortfolio' => [function (array &$payload) {
        $payload = ['other' => []];
    }, 'clientPortfolio'],
    'credit' => [function (array &$payload) {
        unset($payload['clientPortfolio']['credit']);
    }, 'clientPortfolio.credit'],
    'unrealizedPnL' => [function (array &$payload) {
        unset($payload['clientPortfolio']['unrealizedPnL']);
    }, 'clientPortfolio.unrealizedPnL'],
    'positions' => [function (array &$payload) {
        unset($payload['clientPortfolio']['positions']);
    }, 'clientPortfolio.positions'],
    'mirrors' => [function (array &$payload) {
        unset($payload['clientPortfolio']['mirrors']);
    }, 'clientPortfolio.mirrors'],
    'position id' => [function (array &$payload) {
        unset($payload['clientPortfolio']['positions'][1]['positionID']);
    }, 'clientPortfolio.positions[1].positionID'],
    'position amount' => [function (array &$payload) {
        unset($payload['clientPortfolio']['positions'][0]['amount']);
    }, 'clientPortfolio.positions[0].amount'],
    'position isBuy' => [function (array &$payload) {
        unset($payload['clientPortfolio']['positions'][0]['isBuy']);
    }, 'clientPortfolio.positions[0].isBuy'],
    'mirror parentCID' => [function (array &$payload) {
        unset($payload['clientPortfolio']['mirrors'][0]['parentCID']);
    }, 'clientPortfolio.mirrors[0].parentCID'],
    'mirror position instrument' => [function (array &$payload) {
        unset($payload['clientPortfolio']['mirrors'][0]['positions'][1]['instrumentID']);
    }, 'clientPortfolio.mirrors[0].positions[1].instrumentID'],
]);

it('fails on a wrongly typed field without echoing its value', function (Closure $mutate, string $fieldPath, EtoroMappingErrorReason $reason) {
    $payload = accountPnlFixture();
    $mutate($payload);

    $exception = accountPnlMappingError($payload);

    expect($exception->reason)->toBe($reason)
        ->and($exception->fieldPath)->toBe($fieldPath)
        ->and($exception->getMessage())->not->toContain('sensitive-value');
})->with([
    'credit as string' => [function (array &$payload) {
        $payload['clientPortfolio']['credit'] = 'sensitive-value';
    }, 'clientPortfolio.credit', EtoroMappingErrorReason::InvalidPrimitiveType],
    'positions as object' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'] = ['a' => 1];
    }, 'clientPortfolio.positions', EtoroMappingErrorReason::UnexpectedShape],
    'clientPortfolio as list' => [function (array &$payload) {
        $payload['clientPortfolio'] = [1, 2];
    }, 'clientPortfolio', EtoroMappingErrorReason::UnexpectedShape],
    'isBuy as int' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['isBuy'] = 1;
    }, 'clientPortfolio.positions[0].isBuy', EtoroMappingErrorReason::InvalidPrimitiveType],
    'leverage as float' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['leverage'] = 1.5;
    }, 'clientPortfolio.positions[0].leverage', EtoroMappingErrorReason::InvalidPrimitiveType],
    'malformed open time' => [function (array &$payload) {
        $payload['clientPortfolio']['positions'][0]['openDateTime'] = '2026-09-01 10:00';
    }, 'clientPortfolio.positions[0].openDateTime', EtoroMappingErrorReason::MalformedTimestamp],
    'mirror username as int' => [function (array &$payload) {
        $payload['clientPortfolio']['mirrors'][0]['parentUsername'] = 5;
    }, 'clientPortfolio.mirrors[0].parentUsername', EtoroMappingErrorReason::InvalidPrimitiveType],
]);

it('never mutates the input payload', function () {
    $payload = accountPnlFixture();
    $copy = $payload;

    (new AccountPnlMapper)->map($payload);

    expect($payload)->toBe($copy);
});
