<?php

use App\Etoro\Data\TraderProfile;

function checkpointD3Profile(
    string $gcid = '100001',
    string $username = 'trader_001',
    bool $isPopularInvestor = true,
    bool $isVerified = true,
    int $countryCode = 1,
    string $languageIsoCode = 'en-US',
): TraderProfile {
    return new TraderProfile(
        gcid: $gcid,
        username: $username,
        isPopularInvestor: $isPopularInvestor,
        isVerified: $isVerified,
        countryCode: $countryCode,
        languageIsoCode: $languageIsoCode,
    );
}

// --- Valid construction --------------------------------------------------

it('TraderProfile accepts a valid construction with all six fields', function (): void {
    $profile = checkpointD3Profile();

    expect($profile->gcid)->toBe('100001');
    expect($profile->username)->toBe('trader_001');
    expect($profile->isPopularInvestor)->toBeTrue();
    expect($profile->isVerified)->toBeTrue();
    expect($profile->countryCode)->toBe(1);
    expect($profile->languageIsoCode)->toBe('en-US');
});

it('TraderProfile accepts a gcid that merely looks numeric, like "0" and "-1"', function (string $gcid): void {
    $profile = checkpointD3Profile(gcid: $gcid);

    expect($profile->gcid)->toBe($gcid);
})->with([
    '"0"' => ['0'],
    '"-1"' => ['-1'],
]);

it('TraderProfile accepts a numeric-string username as a string', function (): void {
    $profile = checkpointD3Profile(username: '12345');

    expect($profile->username)->toBeString()->toBe('12345');
});

it('TraderProfile accepts a countryCode of 0', function (): void {
    $profile = checkpointD3Profile(countryCode: 0);

    expect($profile->countryCode)->toBe(0);
});

it('TraderProfile does not introduce an unwarranted country range guard', function (int $countryCode): void {
    $profile = checkpointD3Profile(countryCode: $countryCode);

    expect($profile->countryCode)->toBe($countryCode);
})->with([
    'negative' => [-1],
    'large' => [999999],
]);

// --- GCID ------------------------------------------------------------------

it('TraderProfile rejects a blank gcid', function (): void {
    expect(fn () => checkpointD3Profile(gcid: ''))->toThrow(InvalidArgumentException::class);
});

it('TraderProfile rejects a whitespace-only gcid', function (): void {
    expect(fn () => checkpointD3Profile(gcid: '   '))->toThrow(InvalidArgumentException::class);
});

it('TraderProfile rejects a gcid containing a NUL byte', function (): void {
    expect(fn () => checkpointD3Profile(gcid: "100\x00001"))->toThrow(InvalidArgumentException::class);
});

// --- Username ----------------------------------------------------------

it('TraderProfile trims leading/trailing whitespace from username', function (): void {
    $profile = checkpointD3Profile(username: '  trader_001  ');

    expect($profile->username)->toBe('trader_001');
});

it('TraderProfile rejects a blank username', function (): void {
    expect(fn () => checkpointD3Profile(username: ''))->toThrow(InvalidArgumentException::class);
});

it('TraderProfile rejects a whitespace-only username', function (): void {
    expect(fn () => checkpointD3Profile(username: '   '))->toThrow(InvalidArgumentException::class);
});

it('TraderProfile rejects a username with a NUL byte at the start, middle, or end', function (string $username): void {
    expect(fn () => checkpointD3Profile(username: $username))->toThrow(InvalidArgumentException::class);
})->with([
    'NUL at the start' => ["\x00trader_001"],
    'NUL in the middle' => ["trader\x00001"],
    'NUL at the end' => ["trader_001\x00"],
]);

// --- LanguageIsoCode ------------------------------------------------------

it('TraderProfile trims leading/trailing whitespace from languageIsoCode', function (): void {
    $profile = checkpointD3Profile(languageIsoCode: "\ten-US\n");

    expect($profile->languageIsoCode)->toBe('en-US');
});

it('TraderProfile rejects a blank languageIsoCode', function (): void {
    expect(fn () => checkpointD3Profile(languageIsoCode: ''))->toThrow(InvalidArgumentException::class);
});

it('TraderProfile rejects a whitespace-only languageIsoCode', function (): void {
    expect(fn () => checkpointD3Profile(languageIsoCode: '   '))->toThrow(InvalidArgumentException::class);
});

it('TraderProfile rejects a languageIsoCode containing a NUL byte', function (): void {
    expect(fn () => checkpointD3Profile(languageIsoCode: "en\x00US"))->toThrow(InvalidArgumentException::class);
});

it('TraderProfile does not reject an unusual but non-blank languageIsoCode just because it does not match a locale regex', function (string $languageIsoCode): void {
    $profile = checkpointD3Profile(languageIsoCode: $languageIsoCode);

    expect($profile->languageIsoCode)->toBe($languageIsoCode);
})->with([
    'not xx-YY shape' => ['not-a-locale-code'],
    'single letter' => ['e'],
    'numeric-looking' => ['123'],
]);

// --- Other -----------------------------------------------------------------

it('TraderProfile is immutable', function (): void {
    $profile = checkpointD3Profile();

    $property = new ReflectionProperty($profile, 'gcid');

    expect(fn () => $property->setValue($profile, '999'))->toThrow(Error::class);
    expect($profile->gcid)->toBe('100001');
});

it('TraderProfile exposes exactly six public domain properties', function (): void {
    $propertyNames = collect((new ReflectionClass(TraderProfile::class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->map(fn (ReflectionProperty $property): string => $property->getName())
        ->sort()
        ->values()
        ->all();

    expect($propertyNames)->toBe([
        'countryCode',
        'gcid',
        'isPopularInvestor',
        'isVerified',
        'languageIsoCode',
        'username',
    ]);
});

it('TraderProfile does not carry any of the deferred profile fields', function (): void {
    $forbiddenPropertyNames = [
        'firstName',
        'middleName',
        'lastName',
        'aboutMe',
        'aboutMeShort',
        'userBio',
        'avatars',
        'homepage',
        'userFlowSignature',
        'realCID',
        'demoCID',
        'language',
        'strategyID',
        'accountStatus',
        'accountType',
        'piLevel',
        'verificationLevel',
        'raw',
        'rawPayload',
        'extra',
    ];

    $propertyNames = collect((new ReflectionClass(TraderProfile::class))->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName());

    expect($propertyNames->intersect($forbiddenPropertyNames)->values()->all())->toBe([]);
});
