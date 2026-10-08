<?php

use App\Application\Account\AccountValuation;
use App\Etoro\Mappers\AccountPnlMapper;

/**
 * @return array<string, mixed>
 */
function valuationFixture(string $name = 'account-pnl.json'): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/Etoro/'.$name), associative: true, flags: JSON_THROW_ON_ERROR);
}

it('computes invested and equity exactly as the eToro guides define them', function () {
    $valuation = AccountValuation::of((new AccountPnlMapper)->map(valuationFixture()));

    // Invested = 1000 + 500.50 + 1200 + 1000 + (300 − 50) + 100 + 250 + 1.50 = 4302.00
    // Equity   = (5000.25 − 350) + 4302 + (120.40 − 20.15 + 75.30 − 2.05 + 50) = 9175.75
    expect($valuation->investedCents)->toBe(430_200)
        ->and($valuation->equityCents)->toBe(917_575)
        ->and($valuation->equityUnavailableReason)->toBeNull();
});

it('gives equity = credit for an empty account', function () {
    $valuation = AccountValuation::of((new AccountPnlMapper)->map(valuationFixture('account-pnl-empty.json')));

    expect($valuation->investedCents)->toBe(0)
        ->and($valuation->equityCents)->toBe(9_876_543);
});

it('leaves equity unknown when a position P&L is missing, but keeps invested', function () {
    $payload = valuationFixture();
    unset($payload['clientPortfolio']['mirrors'][0]['positions'][0]['unrealizedPnL']);

    $valuation = AccountValuation::of((new AccountPnlMapper)->map($payload));

    expect($valuation->investedCents)->toBe(430_200)
        ->and($valuation->equityCents)->toBeNull()
        ->and($valuation->equityUnavailableReason)->toBe(AccountValuation::REASON_POSITION_PNL_MISSING);
});

it('leaves both unknown when pending orders or copy fields are missing', function (Closure $mutate, string $reason) {
    $payload = valuationFixture();
    $mutate($payload);

    $valuation = AccountValuation::of((new AccountPnlMapper)->map($payload));

    expect($valuation->investedCents)->toBeNull()
        ->and($valuation->equityCents)->toBeNull()
        ->and($valuation->equityUnavailableReason)->toBe($reason);
})->with([
    'orders absent' => [function (array &$payload) {
        unset($payload['clientPortfolio']['orders']);
    }, AccountValuation::REASON_ORDERS_UNKNOWN],
    'order without mirror id' => [function (array &$payload) {
        unset($payload['clientPortfolio']['ordersForOpen'][0]['mirrorId']);
    }, AccountValuation::REASON_ORDERS_UNKNOWN],
    'copy without availableAmount' => [function (array &$payload) {
        unset($payload['clientPortfolio']['mirrors'][0]['availableAmount']);
    }, AccountValuation::REASON_MIRROR_FIELDS_MISSING],
    'copy without positions list' => [function (array &$payload) {
        unset($payload['clientPortfolio']['mirrors'][0]['positions']);
    }, AccountValuation::REASON_MIRROR_FIELDS_MISSING],
]);
