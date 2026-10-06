<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\Calculators\CopySimulationCalculator;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Etoro\EvaluateTraderCopyCoverage;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage;
use App\Application\Traders\StoredPortfolioCoverageAdapter;
use App\Application\Traders\SyncTraderPortfolio;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\Mappers\LivePortfolioMapper;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The stored-snapshot path must give exactly what the existing LIVE path
 * gives for the same portfolio content (docs/DECISIONS.md D-042).
 *
 * @return array<string, mixed>
 */
function storedCoveragePayload(string $variant): array
{
    $payload = json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/live-portfolio.json')), true, flags: JSON_THROW_ON_ERROR);

    if ($variant === 'irregular') {
        // Cash, socialTrades, a duplicate position id, a negative and a
        // zero weight — every data-quality path of the calculator.
        $payload['realizedCreditPct'] = 1.25;
        $payload['socialTrades'] = [['socialTradeId' => 1], ['socialTradeId' => 2]];
        $payload['positions'][1]['positionId'] = $payload['positions'][0]['positionId'];
        $payload['positions'][2]['investmentPct'] = -0.5;
        $payload['positions'][3]['investmentPct'] = 0;
    }

    return $payload;
}

function storedCoverageSnapshot(array $payload): PortfolioSnapshot
{
    Http::fake([
        '*/portfolio/live' => Http::response($payload, 200),
        '*/market-data/*' => Http::response([], 503),
    ]);

    $result = app(SyncTraderPortfolio::class)->handle(Trader::factory()->create(['username' => 'trader_001']));

    return $result->snapshot->refresh();
}

beforeEach(function () {
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value-sentinel',
        'etoro.user_key' => 'test-user-key-value-sentinel',
        'etoro.timeout_seconds' => 5,
        'etoro.connect_timeout_seconds' => 2,
    ]);
    Http::preventStrayRequests();
    Sleep::fake();
});

it('evaluates coverage of a stored snapshot identically to the live use case', function (string $variant, int $copyAmountCents) {
    $payload = storedCoveragePayload($variant);
    $snapshot = storedCoverageSnapshot($payload);

    Http::fake(['*' => Http::response($payload, 200)]);
    $live = app(EvaluateTraderCopyCoverage::class)->handle('trader_001', Money::fromCents($copyAmountCents), Money::fromCents(100));

    $adapter = app(StoredPortfolioCoverageAdapter::class);
    $stored = app(CopyCoverageCalculator::class)->evaluate(
        $adapter->toCopyCoverageRequest($adapter->toLivePortfolio($snapshot), Money::fromCents($copyAmountCents), Money::fromCents(100)),
    );

    expect($stored)->toEqual($live->coverage);
})->with(['fixture' => 'fixture', 'irregular' => 'irregular'])->with([20_000, 50_000, 100_000]);

it('finds the same target minimum from a stored snapshot as the live use case', function (string $variant, int $targetPpb) {
    $payload = storedCoveragePayload($variant);
    $snapshot = storedCoverageSnapshot($payload);

    Http::fake(['*' => Http::response($payload, 200)]);
    $live = app(FindTraderMinimumCopyAmountForCoverage::class)->handle(
        'trader_001',
        Percentage::fromPartsPerBillion($targetPpb),
        Money::fromCents(100),
        Money::fromCents(20_000),
    );

    $adapter = app(StoredPortfolioCoverageAdapter::class);
    $stored = app(CopySimulationCalculator::class)->minimumAmountForTarget(
        $adapter->toCopyCoverageRequest($adapter->toLivePortfolio($snapshot), Money::zero(), Money::fromCents(100)),
        Percentage::fromPartsPerBillion($targetPpb),
        Money::fromCents(20_000),
    );

    expect($stored)->toEqual($live->coverageTarget);
})->with(['fixture' => 'fixture', 'irregular' => 'irregular'])->with([900_000_000, 950_000_000, 990_000_000, 1_000_000_000]);

it('restores the coverage-relevant content of the mapped live portfolio', function () {
    $payload = storedCoveragePayload('irregular');
    $snapshot = storedCoverageSnapshot($payload);

    $mapped = app(LivePortfolioMapper::class)->map($payload);
    $restored = app(StoredPortfolioCoverageAdapter::class)->toLivePortfolio($snapshot);

    expect($restored->socialTradesCount)->toBe(2)
        ->and($restored->cashWeight)->toEqual($mapped->cashWeight)
        ->and(app(LivePortfolioCoverageAdapter::class)->toCopyCoverageRequest($restored, Money::fromCents(20_000), Money::fromCents(100)))
        ->toEqual(app(LivePortfolioCoverageAdapter::class)->toCopyCoverageRequest($mapped, Money::fromCents(20_000), Money::fromCents(100)));

    foreach ($mapped->positions as $index => $position) {
        expect($restored->positions[$index]->positionId)->toBe($position->positionId)
            ->and($restored->positions[$index]->instrumentId)->toBe($position->instrumentId)
            ->and($restored->positions[$index]->weight)->toEqual($position->weight)
            ->and($restored->positions[$index]->openedAt)->toEqual($position->openedAt)
            ->and($restored->positions[$index]->leverage)->toBe($position->leverage)
            ->and($restored->positions[$index]->isBuy)->toBe($position->isBuy);
    }
});
