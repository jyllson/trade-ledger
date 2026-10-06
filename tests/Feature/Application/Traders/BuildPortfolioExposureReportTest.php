<?php

use App\Analytics\Data\ConcentrationGroup;
use App\Analytics\Data\ConcentrationWarning;
use App\Analytics\Data\ExposureStatus;
use App\Analytics\Data\ExposureUnavailableReason;
use App\Application\Traders\BuildPortfolioExposureReport;
use App\Models\Instrument;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;
use Illuminate\Support\Facades\Http;

/**
 * Stored snapshot (cash 10%):
 *
 *   #0  instrument 1001 (Stocks)          30%  1x
 *   #1  instrument 1001 (Stocks)          20%  2x
 *   #2  instrument 2002 (not enriched)    40%  leverage unknown
 *
 * Invested W = 90%. By instrument: 1001 = 50/90, 2002 = 40/90
 *   HHI = (2500 + 1600) / 8100 = 0.506172839506… → 506_172_840
 * By asset class: Stocks 50/90, unknown 40/90 ⇒ same HHI, status partial.
 * Leverage: #2 unknown ⇒ weighted leverage not determinable (null); known
 * contribution (30×1 + 20×2) / 90 = 0.777777777|78; known weight 50/90 =
 * 0.5555555555|6; unknown = 40/90 = 0.4444444444
 */
function storedExposureSnapshot(?Trader $trader = null, string $capturedAt = '2026-10-05 10:00:00'): PortfolioSnapshot
{
    $stocks = Instrument::factory()->enriched()->create(['external_instrument_id' => '1001', 'asset_class' => 'Stocks', 'stocks_industry_id' => 7]);
    $bare = Instrument::factory()->create(['external_instrument_id' => '2002']);

    $snapshot = PortfolioSnapshot::factory()->for($trader ?? Trader::factory())->create([
        'captured_at' => $capturedAt,
        'cash_weight_ppb' => 100_000_000,
        'invested_weight_ppb' => 900_000_000,
        'position_count' => 3,
    ]);

    foreach ([[$stocks, 300_000_000, 1], [$stocks, 200_000_000, 2], [$bare, 400_000_000, null]] as $index => [$instrument, $weight, $leverage]) {
        PortfolioPosition::factory()->for($snapshot, 'snapshot')->create([
            'position_index' => $index,
            'external_position_id' => 'pos-'.$index,
            'instrument_id' => $instrument->id,
            'external_instrument_id' => $instrument->external_instrument_id,
            'weight_ppb' => $weight,
            'leverage' => $leverage,
        ]);
    }

    return $snapshot;
}

it('builds concentration and leverage from a stored snapshot without any HTTP call', function () {
    Http::preventStrayRequests();
    $snapshot = storedExposureSnapshot();

    $report = app(BuildPortfolioExposureReport::class)->handle($snapshot);

    $instrumentRows = array_map(static fn (ConcentrationGroup $group): array => [$group->key, $group->weight->partsPerBillion(), $group->positionCount], $report->concentration->byInstrument->groups);

    expect($report->portfolioSnapshotId)->toBe($snapshot->id)
        ->and($report->capturedAt->format('Y-m-d H:i:s'))->toBe('2026-10-05 10:00:00')
        ->and($instrumentRows)->toBe([['1001', 555_555_556, 2], ['2002', 444_444_444, 1]])
        ->and($report->concentration->byInstrument->hhi->partsPerBillion())->toBe(506_172_840)
        ->and($report->concentration->byAssetClass->status)->toBe(ExposureStatus::Partial)
        ->and($report->concentration->byAssetClass->unclassifiedWeight->partsPerBillion())->toBe(444_444_444)
        ->and($report->concentration->byAssetClass->groups[1]->isUnknown())->toBeTrue()
        ->and($report->concentration->investedWeight->partsPerBillion())->toBe(900_000_000)
        ->and($report->concentration->unaccountedWeight->partsPerBillion())->toBe(0)
        ->and($report->concentration->warnings)->toBe([ConcentrationWarning::AssetClassUnknown])
        ->and($report->leverage->status)->toBe(ExposureStatus::Partial)
        ->and($report->leverage->weightedLeverage)->toBeNull()
        ->and($report->leverage->knownLeverageContribution)->toBe('0.777777778')
        ->and($report->leverage->knownLeverageWeight->partsPerBillion())->toBe(555_555_556)
        ->and($report->leverage->unknownLeverageWeight->partsPerBillion())->toBe(444_444_444)
        ->and($report->leverage->missingLeverageCount)->toBe(1);
});

it('does not compute sector concentration from the stored industry id', function () {
    $report = app(BuildPortfolioExposureReport::class)->handle(storedExposureSnapshot());

    expect($report->concentration->bySector->status)->toBe(ExposureStatus::Unavailable)
        ->and($report->concentration->bySector->unavailableReason)->toBe(ExposureUnavailableReason::ClassificationNotSupported);
});

it('passes an unknown cash weight through as unknown', function () {
    $snapshot = storedExposureSnapshot();
    $snapshot->update(['cash_weight_ppb' => null]);

    $report = app(BuildPortfolioExposureReport::class)->handle($snapshot->refresh());

    expect($report->concentration->cashWeight)->toBeNull()
        ->and($report->concentration->unaccountedWeight)->toBeNull()
        ->and($report->concentration->warnings)->toContain(ConcentrationWarning::CashWeightUnknown);
});

it('handles a stored snapshot with no positions', function () {
    $snapshot = PortfolioSnapshot::factory()->create(['cash_weight_ppb' => 1_000_000_000, 'invested_weight_ppb' => 0]);

    $report = app(BuildPortfolioExposureReport::class)->handle($snapshot);

    expect($report->concentration->positionCount)->toBe(0)
        ->and($report->concentration->byInstrument->unavailableReason)->toBe(ExposureUnavailableReason::NoInvestedWeight)
        ->and($report->leverage->status)->toBe(ExposureStatus::Unavailable);
});

it('reports the latest captured snapshot of a trader, or null when none is stored', function () {
    $trader = Trader::factory()->create();
    $builder = app(BuildPortfolioExposureReport::class);

    expect($builder->latestForTrader($trader))->toBeNull();

    $older = PortfolioSnapshot::factory()->for($trader)->create(['captured_at' => '2026-10-01 10:00:00']);
    $latest = storedExposureSnapshot($trader, '2026-10-05 10:00:00');
    PortfolioSnapshot::factory()->create(['captured_at' => '2026-10-06 10:00:00']);

    expect($builder->latestForTrader($trader)->portfolioSnapshotId)->toBe($latest->id)
        ->and($older->id)->not->toBe($latest->id);
});
