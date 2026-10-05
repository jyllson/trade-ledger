<?php

use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\GainGranularity;
use App\Etoro\Mappers\GainHistoryMapper;

/**
 * @return array<string, mixed>
 */
function gainHistoryMonthlyFixture(): array
{
    $json = file_get_contents(__DIR__.'/../../../Fixtures/Etoro/gain-history-monthly.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
}

function expectGainHistoryMappingException(callable $callback): EtoroMappingException
{
    try {
        $callback();
    } catch (EtoroMappingException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an EtoroMappingException to be thrown.');
}

it('maps the synthetic v2 monthly fixture with exact decimal-fraction gains', function () {
    $history = (new GainHistoryMapper)->map(gainHistoryMonthlyFixture(), GainGranularity::Monthly);

    expect($history->username)->toBe('trader_001')
        ->and($history->granularity)->toBe(GainGranularity::Monthly)
        ->and($history->points)->toHaveCount(26)
        ->and($history->points[0]->date->format('Y-m-d'))->toBe('2011-03-09')
        ->and($history->points[0]->date->getTimezone()->getName())->toBe('UTC')
        ->and($history->points[0]->gain->partsPerBillion())->toBe(12_500_000)
        ->and($history->points[2]->gain->partsPerBillion())->toBe(-14_200_000)
        ->and($history->points[3]->gain->partsPerBillion())->toBe(0)
        ->and($history->totalGain->partsPerBillion())->toBe(238_246_000);
});

it('fails closed when the response granularity differs from the requested one', function () {
    $exception = expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map(gainHistoryMonthlyFixture(), GainGranularity::Daily));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue)
        ->and($exception->fieldPath)->toBe('granularity');
});

it('sorts an unsorted but otherwise valid series ascending', function () {
    $payload = gainHistoryMonthlyFixture();
    $payload['gains'] = array_reverse($payload['gains']);

    $history = (new GainHistoryMapper)->map($payload, GainGranularity::Monthly);

    expect($history->points[0]->date->format('Y-m-d'))->toBe('2011-03-09')
        ->and($history->points[25]->date->format('Y-m-d'))->toBe('2013-04-01');
});

it('rejects a duplicate date, reporting the second occurrence', function () {
    $payload = gainHistoryMonthlyFixture();
    $payload['gains'][5]['date'] = $payload['gains'][4]['date'];

    $exception = expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map($payload, GainGranularity::Monthly));

    expect($exception->fieldPath)->toBe('gains[5].date');
});

it('rejects malformed or lenient dates', function (mixed $date) {
    $payload = gainHistoryMonthlyFixture();
    $payload['gains'][1]['date'] = $date;

    $exception = expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map($payload, GainGranularity::Monthly));

    expect($exception->fieldPath)->toBe('gains[1].date');
})->with(['2011-4-01', '2011-02-30', '2011-04-01T00:00:00Z', '', "2011-04-01\0", 20110401]);

it('rejects a non-numeric or non-finite gain', function (mixed $gain) {
    $payload = gainHistoryMonthlyFixture();
    $payload['gains'][0]['gain'] = $gain;

    $exception = expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map($payload, GainGranularity::Monthly));

    expect($exception->fieldPath)->toBe('gains[0].gain');
})->with(['0.01', null, true, [[]], INF, NAN, -1, -1.5, -0.9999999996]);

it('requires username, granularity, gains, and per-point gain', function (string $remove, string $fieldPath) {
    $payload = gainHistoryMonthlyFixture();

    if (str_starts_with($remove, 'gains[0].')) {
        unset($payload['gains'][0][substr($remove, 9)]);
    } else {
        unset($payload[$remove]);
    }

    $exception = expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map($payload, GainGranularity::Monthly));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField)
        ->and($exception->fieldPath)->toBe($fieldPath);
})->with([
    ['username', 'username'],
    ['granularity', 'granularity'],
    ['gains', 'gains'],
    ['gains[0].gain', 'gains[0].gain'],
    ['gains[0].date', 'gains[0].date'],
]);

it('rejects a blank username and a non-list gains value', function () {
    $blank = gainHistoryMonthlyFixture();
    $blank['username'] = '  ';
    $assoc = gainHistoryMonthlyFixture();
    $assoc['gains'] = ['a' => $assoc['gains'][0]];

    expect(expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map($blank, GainGranularity::Monthly))->fieldPath)->toBe('username')
        ->and(expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map($assoc, GainGranularity::Monthly))->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
});

it('accepts a null or missing totalGain and an empty series, as documented', function () {
    $payload = ['username' => 'trader_001', 'granularity' => 'daily', 'totalGain' => null, 'gains' => []];

    $history = (new GainHistoryMapper)->map($payload, GainGranularity::Daily);
    unset($payload['totalGain']);
    $withoutTotal = (new GainHistoryMapper)->map($payload, GainGranularity::Daily);

    expect($history->totalGain)->toBeNull()
        ->and($history->points)->toBe([])
        ->and($withoutTotal->totalGain)->toBeNull();
});

it('ignores unknown extra fields and never mutates the input', function () {
    $payload = gainHistoryMonthlyFixture();
    $payload['unexpected'] = ['x' => 1];
    $payload['gains'][0]['extra'] = 'ignored';
    $before = $payload;

    (new GainHistoryMapper)->map($payload, GainGranularity::Monthly);

    expect($payload)->toBe($before);
});

it('never exposes source values in mapping exception messages', function () {
    $payload = gainHistoryMonthlyFixture();
    $payload['gains'][0]['date'] = 'SENTINEL_DATE_VALUE';

    $exception = expectGainHistoryMappingException(fn () => (new GainHistoryMapper)->map($payload, GainGranularity::Monthly));

    expect($exception->getMessage())->not->toContain('SENTINEL_DATE_VALUE')
        ->not->toContain('trader_001');
});
