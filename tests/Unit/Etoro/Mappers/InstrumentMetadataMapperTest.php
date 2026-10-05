<?php

use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\InstrumentMetadataMapper;

/**
 * @return array<string, mixed>
 */
function instrumentFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/../../../Fixtures/Etoro/'.$name), true, flags: JSON_THROW_ON_ERROR);
}

function expectInstrumentMappingException(callable $callback): EtoroMappingException
{
    try {
        $callback();
    } catch (EtoroMappingException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an EtoroMappingException.');
}

it('maps instrument display data, accepting the observed stocksIndustryID spelling', function () {
    $rows = (new InstrumentMetadataMapper)->mapDisplayData(instrumentFixture('instrument-display-data.json'));

    expect($rows)->toHaveCount(6)
        ->and($rows[0]->instrumentId)->toBe(500001)
        ->and($rows[0]->displayName)->toBe('Synthetic Equity Alpha')
        ->and($rows[0]->symbolFull)->toBe('SYNA')
        ->and($rows[0]->instrumentTypeId)->toBe(5)
        ->and($rows[0]->exchangeId)->toBe(900)
        ->and($rows[0]->stocksIndustryId)->toBe(70)
        ->and($rows[3]->stocksIndustryId)->toBeNull();
});

it('also accepts the documented stocksIndustryId spelling', function () {
    $payload = ['instrumentDisplayDatas' => [['instrumentID' => 7, 'stocksIndustryId' => 12]]];

    expect((new InstrumentMetadataMapper)->mapDisplayData($payload)[0]->stocksIndustryId)->toBe(12);
});

it('degrades malformed optional display fields to null instead of dropping the row', function () {
    $payload = ['instrumentDisplayDatas' => [[
        'instrumentID' => 7,
        'instrumentDisplayName' => ['not', 'a', 'string'],
        'symbolFull' => '   ',
        'instrumentTypeID' => '5',
        'exchangeID' => -1,
    ]]];

    $row = (new InstrumentMetadataMapper)->mapDisplayData($payload)[0];

    expect($row->displayName)->toBeNull()
        ->and($row->symbolFull)->toBeNull()
        ->and($row->instrumentTypeId)->toBeNull()
        ->and($row->exchangeId)->toBeNull();
});

it('requires a positive integer instrumentID', function (mixed $id, EtoroMappingErrorReason $reason) {
    $payload = ['instrumentDisplayDatas' => [$id === 'missing' ? [] : ['instrumentID' => $id]]];

    $exception = expectInstrumentMappingException(fn () => (new InstrumentMetadataMapper)->mapDisplayData($payload));

    expect($exception->reason)->toBe($reason)
        ->and($exception->fieldPath)->toBe('instrumentDisplayDatas[0].instrumentID');
})->with([
    ['missing', EtoroMappingErrorReason::MissingRequiredField],
    ['5', EtoroMappingErrorReason::InvalidPrimitiveType],
    [0, EtoroMappingErrorReason::InvalidValue],
]);

it('maps the instrument type catalogue', function () {
    $types = (new InstrumentMetadataMapper)->mapInstrumentTypes(instrumentFixture('instrument-types.json'));

    expect($types)->toHaveCount(10)
        ->and($types[4]->id)->toBe(5)
        ->and($types[4]->description)->toBe('Stocks');
});

it('requires a description for every instrument type', function () {
    $exception = expectInstrumentMappingException(fn () => (new InstrumentMetadataMapper)->mapInstrumentTypes(['instrumentTypes' => [['instrumentTypeID' => 1]]]));

    expect($exception->fieldPath)->toBe('instrumentTypes[0].instrumentTypeDescription');
});

it('rejects a missing or non-list top-level array', function (array $payload, string $method) {
    expect(fn () => (new InstrumentMetadataMapper)->{$method}($payload))->toThrow(EtoroMappingException::class);
})->with([
    [[], 'mapDisplayData'],
    [['instrumentDisplayDatas' => ['a' => []]], 'mapDisplayData'],
    [['instrumentTypes' => 'x'], 'mapInstrumentTypes'],
]);
