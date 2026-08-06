<?php

use App\Etoro\Data\TraderProfile;
use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\TraderProfileMapper;

/**
 * Loads a fresh decode of the synthetic fixture on every call — deliberately
 * avoids any Laravel helper (base_path()/app_path()) so this pure-PHP Unit
 * test suite never depends on the framework container being bootstrapped.
 *
 * @return array<string, mixed>
 */
function checkpointD3ProfileFixture(): array
{
    $json = file_get_contents(__DIR__.'/../../../Fixtures/Etoro/public-profile.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
}

function expectCheckpointD3ProfileMappingException(callable $callback): EtoroMappingException
{
    try {
        $callback();
    } catch (EtoroMappingException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an EtoroMappingException to be thrown.');
}

// --- Successful fixture mapping --------------------------------------------

it('maps the fixture successfully', function (): void {
    $profile = (new TraderProfileMapper)->map(checkpointD3ProfileFixture());

    expect($profile)->toBeInstanceOf(TraderProfile::class);
});

it('maps all six fields from the fixture', function (): void {
    $profile = (new TraderProfileMapper)->map(checkpointD3ProfileFixture());

    expect($profile->gcid)->toBe('100001');
    expect($profile->username)->toBe('trader_001');
    expect($profile->isPopularInvestor)->toBeTrue();
    expect($profile->isVerified)->toBeTrue();
    expect($profile->countryCode)->toBe(1);
    expect($profile->languageIsoCode)->toBe('en-US');
});

it('normalizes the fixture\'s integer gcid to a string', function (): void {
    $profile = (new TraderProfileMapper)->map(checkpointD3ProfileFixture());

    expect($profile->gcid)->toBeString()->toBe('100001');
});

it('maps isPi to isPopularInvestor', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['isPi'] = false;

    $profile = (new TraderProfileMapper)->map($fixture);

    expect($profile->isPopularInvestor)->toBeFalse();
});

it('keeps username and languageIsoCode as strings', function (): void {
    $profile = (new TraderProfileMapper)->map(checkpointD3ProfileFixture());

    expect($profile->username)->toBeString();
    expect($profile->languageIsoCode)->toBeString();
});

it('does not expose any ignored PII/URL/nested fixture field as a DTO property', function (): void {
    $profile = (new TraderProfileMapper)->map(checkpointD3ProfileFixture());

    foreach (['firstName', 'lastName', 'aboutMe', 'aboutMeShort', 'userBio', 'avatars', 'homepage', 'userFlowSignature', 'realCID', 'demoCID'] as $field) {
        expect(property_exists($profile, $field))->toBeFalse();
    }
});

// --- Top-level / users shape ---------------------------------------------

it('rejects a missing users key', function (): void {
    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map([]));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('users');
});

it('rejects a scalar users value', function (): void {
    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map(['users' => 'not-an-array']));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users');
});

it('rejects an associative array for users', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'] = ['first' => $fixture['users'][0]];

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('users');
});

it('rejects an empty users list', function (): void {
    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map(['users' => []]));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('users');
    expect($exception->getMessage())->not->toContain('0');
});

it('rejects a users list with more than one item', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][] = $fixture['users'][0];

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('users');
    expect($exception->getMessage())->not->toContain('2');
});

it('rejects a scalar user item', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0] = 'not-an-array';

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0]');
});

it('rejects a non-empty list for a user item', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0] = ['a', 'b'];

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('users[0]');
});

it('treats an empty user item as missing its first required field', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0] = [];

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('users[0].gcid');
});

// --- Required fields ---------------------------------------------------

it('rejects a user item missing a required field', function (string $missingField): void {
    $fixture = checkpointD3ProfileFixture();
    unset($fixture['users'][0][$missingField]);

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe("users[0].{$missingField}");
})->with(['gcid', 'username', 'isPi', 'isVerified', 'country', 'languageIsoCode']);

// --- Primitive type errors -----------------------------------------------

it('rejects a gcid of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['gcid'] = $wrongValue;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0].gcid');
})->with([
    'float' => [1.5],
    'null' => [null],
    'bool' => [true],
    'array' => [[1]],
]);

it('rejects a username of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['username'] = $wrongValue;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0].username');
})->with([
    'int' => [100001],
    'float' => [1.5],
    'bool' => [true],
    'null' => [null],
    'array' => [['a']],
]);

it('rejects an isPi of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['isPi'] = $wrongValue;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0].isPi');
})->with([
    'int' => [1],
    'string' => ['true'],
    'null' => [null],
]);

it('rejects an isVerified of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['isVerified'] = $wrongValue;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0].isVerified');
})->with([
    'int' => [0],
    'string' => ['false'],
    'null' => [null],
]);

it('rejects a country of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['country'] = $wrongValue;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0].country');
})->with([
    'float' => [1.5],
    'numeric string' => ['1'],
    'bool' => [true],
    'null' => [null],
]);

it('rejects a languageIsoCode of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['languageIsoCode'] = $wrongValue;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0].languageIsoCode');
})->with([
    'int' => [1],
    'float' => [1.5],
    'bool' => [true],
    'null' => [null],
    'array' => [['en-US']],
]);

// --- Invalid string values -----------------------------------------------

it('rejects a blank or whitespace-only gcid', function (string $raw): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['gcid'] = $raw;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('users[0].gcid');
})->with([
    'blank' => [''],
    'whitespace-only' => ['   '],
]);

it('rejects a gcid containing a NUL byte', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['gcid'] = "100\x00001";

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('users[0].gcid');
});

it('rejects a blank or whitespace-only username', function (string $raw): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['username'] = $raw;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('users[0].username');
})->with([
    'blank' => [''],
    'whitespace-only' => ['   '],
]);

it('rejects a username containing a NUL byte', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['username'] = "trader\x00001";

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('users[0].username');
});

it('rejects a blank or whitespace-only languageIsoCode', function (string $raw): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['languageIsoCode'] = $raw;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('users[0].languageIsoCode');
})->with([
    'blank' => [''],
    'whitespace-only' => ['   '],
]);

it('rejects a languageIsoCode containing a NUL byte', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['languageIsoCode'] = "en\x00US";

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('users[0].languageIsoCode');
});

// --- Identifier / string behavior -----------------------------------------

it('normalizes an int gcid to a string', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['gcid'] = 999999;

    $profile = (new TraderProfileMapper)->map($fixture);

    expect($profile->gcid)->toBeString()->toBe('999999');
});

it('keeps a numeric string gcid as the same string', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['gcid'] = '100001';

    $profile = (new TraderProfileMapper)->map($fixture);

    expect($profile->gcid)->toBe('100001');
});

it('trims whitespace around a string gcid', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['gcid'] = '  100001  ';

    $profile = (new TraderProfileMapper)->map($fixture);

    expect($profile->gcid)->toBe('100001');
});

it('rejects an int username instead of silently converting it to a string', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['username'] = 100001;

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('users[0].username');
});

it('keeps a numeric-string username as a string', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['username'] = '12345';

    $profile = (new TraderProfileMapper)->map($fixture);

    expect($profile->username)->toBeString()->toBe('12345');
});

it('trims whitespace around username and languageIsoCode', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['username'] = '  trader_001  ';
    $fixture['users'][0]['languageIsoCode'] = " en-US\t";

    $profile = (new TraderProfileMapper)->map($fixture);

    expect($profile->username)->toBe('trader_001');
    expect($profile->languageIsoCode)->toBe('en-US');
});

// --- Unknown / omitted fields ------------------------------------------

it('ignores deferred profile fields, even with an unusual or nested shape', function (string $field, mixed $unusualValue): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0][$field] = $unusualValue;

    expect(fn () => (new TraderProfileMapper)->map($fixture))->not->toThrow(Throwable::class);
})->with([
    'firstName as array' => ['firstName', ['nested' => 'shape']],
    'lastName as int' => ['lastName', 123],
    'aboutMe as object' => ['aboutMe', ['x' => 1]],
    'aboutMeShort as bool' => ['aboutMeShort', true],
    'userBio as scalar' => ['userBio', 'not-an-object'],
    'avatars as scalar' => ['avatars', 'not-a-list'],
    'homepage as array' => ['homepage', ['url' => 'https://example.invalid']],
    'userFlowSignature as int' => ['userFlowSignature', 12345],
    'realCID as string' => ['realCID', 'not-an-int'],
    'demoCID as array' => ['demoCID', [1, 2, 3]],
    'customerRestrictions as object' => ['customerRestrictions', ['blocked' => true]],
    'gdprInfo as scalar' => ['gdprInfo', 'unexpected'],
]);

// --- Other -----------------------------------------------------------------

it('does not mutate the input payload', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $originalJson = json_encode($fixture);

    (new TraderProfileMapper)->map($fixture);

    expect(json_encode($fixture))->toBe($originalJson);
});

it('two calls with the same payload produce an identical result', function (): void {
    $fixture = checkpointD3ProfileFixture();

    $first = (new TraderProfileMapper)->map($fixture);
    $second = (new TraderProfileMapper)->map($fixture);

    $extract = fn (TraderProfile $p) => [$p->gcid, $p->username, $p->isPopularInvestor, $p->isVerified, $p->countryCode, $p->languageIsoCode];

    expect($extract($second))->toBe($extract($first));
});

it('does not cross-check userBio.gcid against the top-level gcid', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['userBio']['gcid'] = 999999;

    $profile = (new TraderProfileMapper)->map($fixture);

    expect($profile->gcid)->toBe('100001');
});

it('does not include a mutated sentinel value in mapper exception messages', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['username'] = "sentinel-marker-do-not-leak\x00";

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->getMessage())->not->toContain('sentinel-marker-do-not-leak');
});

it('does not include PII, URL, or opaque signature values from ignored fields in mapper exception messages', function (): void {
    $fixture = checkpointD3ProfileFixture();
    // Force an unrelated failure (missing country) while PII/URL/token
    // fields remain present elsewhere in the same item, proving the mapper
    // never echoes ignored field content into any exception.
    unset($fixture['users'][0]['country']);

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->getMessage())->not->toContain($fixture['users'][0]['firstName']);
    expect($exception->getMessage())->not->toContain($fixture['users'][0]['lastName']);
    expect($exception->getMessage())->not->toContain($fixture['users'][0]['aboutMe']);
    expect($exception->getMessage())->not->toContain($fixture['users'][0]['userFlowSignature']);
    expect($exception->getMessage())->not->toContain($fixture['users'][0]['avatars'][0]['url']);
});

it('reports the exact expectedType/actualType on a wrong-type exception', function (): void {
    $fixture = checkpointD3ProfileFixture();
    $fixture['users'][0]['country'] = 'not-an-int';

    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map($fixture));

    expect($exception->expectedType)->toBe('int');
    expect($exception->actualType)->toBe('string');
});

it('reports the exact reason and fieldPath on a shape exception', function (): void {
    $exception = expectCheckpointD3ProfileMappingException(fn () => (new TraderProfileMapper)->map(['users' => []]));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('users');
});
