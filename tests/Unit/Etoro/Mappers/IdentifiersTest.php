<?php

use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\Support\Identifiers;

it('normalizes an int to a string', function (): void {
    expect(Identifiers::normalize(700001, 'TestMapper', 'positionId'))->toBe('700001');
});

it('does not reject zero or a negative int without confirmed API semantics', function (int $raw, string $expected): void {
    expect(Identifiers::normalize($raw, 'TestMapper', 'positionId'))->toBe($expected);
})->with([
    'zero' => [0, '0'],
    'negative' => [-1, '-1'],
]);

it('normalizes a normal string as-is', function (): void {
    expect(Identifiers::normalize('abc123', 'TestMapper', 'positionId'))->toBe('abc123');
});

it('keeps a numeric string as a string, not converted to an int', function (): void {
    expect(Identifiers::normalize('007', 'TestMapper', 'positionId'))->toBe('007');
});

it('trims external whitespace from a string', function (): void {
    expect(Identifiers::normalize('  700001  ', 'TestMapper', 'positionId'))->toBe('700001');
});

it('rejects an empty or whitespace-only string as InvalidValue', function (string $raw): void {
    try {
        Identifiers::normalize($raw, 'TestMapper', 'positionId');
        expect(false)->toBeTrue('Expected EtoroMappingException was not thrown.');
    } catch (EtoroMappingException $exception) {
        expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
        expect($exception->mapper)->toBe('TestMapper');
        expect($exception->fieldPath)->toBe('positionId');
    }
})->with([
    'empty string' => [''],
    'spaces only' => ['   '],
    'tabs and newlines' => ["\t\n"],
]);

it('rejects a string containing a NUL byte as InvalidValue', function (string $raw): void {
    try {
        Identifiers::normalize($raw, 'TestMapper', 'positionId');
        expect(false)->toBeTrue('Expected EtoroMappingException was not thrown.');
    } catch (EtoroMappingException $exception) {
        expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
        expect($exception->mapper)->toBe('TestMapper');
        expect($exception->fieldPath)->toBe('positionId');
        expect($exception->getMessage())->not->toContain('sentinel-nul-marker');
    }
})->with([
    'NUL at the start' => ["\0sentinel-nul-marker"],
    'NUL in the middle' => ["sentinel\0-nul-marker"],
    'NUL at the end' => ["sentinel-nul-marker\0"],
]);

it('rejects float, null, bool, array, and object as InvalidPrimitiveType', function (mixed $raw): void {
    try {
        Identifiers::normalize($raw, 'TestMapper', 'positionId');
        expect(false)->toBeTrue('Expected EtoroMappingException was not thrown.');
    } catch (EtoroMappingException $exception) {
        expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    }
})->with([
    'float' => [1.5],
    'null' => [null],
    'bool' => [true],
    'array' => [[1]],
    'object' => [new stdClass],
]);

it('never includes the raw sentinel value in the exception message', function (mixed $raw, string $forbiddenSubstring): void {
    try {
        Identifiers::normalize($raw, 'TestMapper', 'positionId');
        expect(false)->toBeTrue('Expected EtoroMappingException was not thrown.');
    } catch (EtoroMappingException $exception) {
        expect($exception->getMessage())->not->toContain($forbiddenSubstring);
    }
})->with([
    'distinctive float' => [700001.5, '700001'],
    'distinctive array content' => [['secret-marker-12345'], 'secret-marker-12345'],
]);
