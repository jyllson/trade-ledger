<?php

use App\Analytics\Calculators\ConsistencyCalculator;
use App\Analytics\Calculators\DrawdownCalculator;
use App\Analytics\Calculators\PerformanceCalculator;
use App\Etoro\Adapters\GainHistoryReturnSeriesAdapter;
use App\Etoro\GainGranularity;
use App\Etoro\Mappers\GainHistoryMapper;

/**
 * Fixture → mapper → adapter → calculators. Proves the pieces compose and
 * that our own compounding agrees with the API's totalGain (which the
 * fixture rounds to 6 decimals, i.e. within 1_000 ppb).
 */
function gainHistoryPipelineSeries(string $asOf)
{
    $payload = json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/gain-history-monthly.json')), true, flags: JSON_THROW_ON_ERROR);
    $history = (new GainHistoryMapper)->map($payload, GainGranularity::Monthly);

    return [$history, (new GainHistoryReturnSeriesAdapter)->toReturnSeries($history, new DateTimeImmutable($asOf, new DateTimeZone('UTC')))];
}

it('reproduces the API totalGain by compounding every period', function () {
    [$history, $series] = gainHistoryPipelineSeries('2013-04-15');

    $summary = (new PerformanceCalculator)->calculate($series);

    expect(abs($summary->cumulativeReturn->partsPerBillion() - $history->totalGain->partsPerBillion()))->toBeLessThanOrEqual(1_000)
        ->and($summary->periodCount)->toBe(26)
        ->and($summary->hasPartialStart)->toBeTrue()
        ->and($summary->hasInProgressPeriod)->toBeTrue()
        ->and($summary->completePeriodCount)->toBe(24)
        ->and($summary->trailing12Return)->not->toBeNull()
        ->and($summary->trailing24Return)->not->toBeNull();
});

it('treats the last month as complete once the capture is in a later month', function () {
    [, $series] = gainHistoryPipelineSeries('2013-05-02');

    expect((new PerformanceCalculator)->calculate($series)->completePeriodCount)->toBe(25);
});

it('runs drawdown and consistency over the same series with explicit monthly granularity', function () {
    [, $series] = gainHistoryPipelineSeries('2013-04-15');

    $drawdown = (new DrawdownCalculator)->calculate($series);
    $consistency = (new ConsistencyCalculator)->calculate($series);

    expect($drawdown->granularity->value)->toBe('monthly')
        ->and($drawdown->maxDrawdown->isPositive())->toBeTrue()
        ->and($consistency->observedCount)->toBe(24)
        ->and($consistency->positiveCount + $consistency->negativeCount + $consistency->flatCount)->toBe(24)
        ->and($consistency->annualizedVolatility)->not->toBeNull();
});
