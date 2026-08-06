<?php

use App\Analytics\ValueObjects\Percentage;
use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition;

it('constructs with required fields and null optional fields by default', function (): void {
    $position = new PortfolioPosition(
        positionId: '700001',
        instrumentId: '500001',
        weight: Percentage::fromEtoroInvestmentPct(0.1),
    );

    expect($position->positionId)->toBe('700001');
    expect($position->instrumentId)->toBe('500001');
    expect($position->weight->partsPerBillion())->toBe(1_000_000);
    expect($position->openedAt)->toBeNull();
    expect($position->isBuy)->toBeNull();
    expect($position->leverage)->toBeNull();
    expect($position->takeProfitRate)->toBeNull();
    expect($position->stopLossRate)->toBeNull();
    expect($position->trailingStopLoss)->toBeNull();
});

it('constructs with all optional fields populated', function (): void {
    $openedAt = new DateTimeImmutable('2012-01-10T09:15:00+00:00');

    $position = new PortfolioPosition(
        positionId: '700001',
        instrumentId: '500001',
        weight: Percentage::fromEtoroInvestmentPct(0.1),
        openedAt: $openedAt,
        isBuy: true,
        leverage: 2,
        takeProfitRate: 23.0,
        stopLossRate: 18.0,
        trailingStopLoss: false,
    );

    expect($position->openedAt)->toBe($openedAt);
    expect($position->isBuy)->toBeTrue();
    expect($position->leverage)->toBe(2);
    expect($position->takeProfitRate)->toBe(23.0);
    expect($position->stopLossRate)->toBe(18.0);
    expect($position->trailingStopLoss)->toBeFalse();
});

it('rejects a blank positionId', function (string $blank): void {
    expect(fn () => new PortfolioPosition(
        positionId: $blank,
        instrumentId: '500001',
        weight: Percentage::zero(),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'whitespace only' => ['   '],
]);

it('rejects a blank instrumentId', function (string $blank): void {
    expect(fn () => new PortfolioPosition(
        positionId: '700001',
        instrumentId: $blank,
        weight: Percentage::zero(),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'whitespace only' => ['   '],
]);

it('PortfolioPosition is immutable', function (): void {
    $position = new PortfolioPosition('700001', '500001', Percentage::zero());

    $property = new ReflectionProperty($position, 'positionId');

    expect(fn () => $property->setValue($position, '999'))->toThrow(Error::class);
    expect($position->positionId)->toBe('700001');
});

it('accepts an empty positions list', function (): void {
    $portfolio = new LivePortfolio(positions: [], socialTradesCount: 0);

    expect($portfolio->positions)->toBe([]);
});

it('rejects a non-list positions array', function (): void {
    $position = new PortfolioPosition('700001', '500001', Percentage::zero());

    expect(fn () => new LivePortfolio(positions: [1 => $position], socialTradesCount: 0))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a positions element of the wrong type', function (): void {
    expect(fn () => new LivePortfolio(positions: ['not a position'], socialTradesCount: 0))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a negative socialTradesCount', function (): void {
    expect(fn () => new LivePortfolio(positions: [], socialTradesCount: -1))
        ->toThrow(InvalidArgumentException::class);
});

it('hasUnmodeledSocialTrades is false when socialTradesCount is 0', function (): void {
    expect((new LivePortfolio(positions: [], socialTradesCount: 0))->hasUnmodeledSocialTrades())->toBeFalse();
});

it('hasUnmodeledSocialTrades is true when socialTradesCount is greater than 0', function (): void {
    expect((new LivePortfolio(positions: [], socialTradesCount: 3))->hasUnmodeledSocialTrades())->toBeTrue();
});

it('LivePortfolio is immutable', function (): void {
    $portfolio = new LivePortfolio(positions: [], socialTradesCount: 0);

    $property = new ReflectionProperty($portfolio, 'socialTradesCount');

    expect(fn () => $property->setValue($portfolio, 5))->toThrow(Error::class);
    expect($portfolio->socialTradesCount)->toBe(0);
});
