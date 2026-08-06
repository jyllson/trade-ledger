<?php

use App\Etoro\Data\RankingPage;
use App\Etoro\Exceptions\EtoroMappingErrorReason;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Mappers\RankingsMapper;

/**
 * Loads a fresh decode of the synthetic fixture on every call — deliberately
 * avoids any Laravel helper (base_path()/app_path()) so this pure-PHP Unit
 * test suite never depends on the framework container being bootstrapped.
 *
 * @return array<string, mixed>
 */
function checkpointD2RankingsFixture(): array
{
    $json = file_get_contents(__DIR__.'/../../../Fixtures/Etoro/rankings.json');

    return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
}

function expectCheckpointD2RankingsMappingException(callable $callback): EtoroMappingException
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
    $page = (new RankingsMapper)->map(checkpointD2RankingsFixture());

    expect($page)->toBeInstanceOf(RankingPage::class);
});

it('maps the exact number of entries in the fixture', function (): void {
    $page = (new RankingsMapper)->map(checkpointD2RankingsFixture());

    expect($page->entries)->toHaveCount(3);
});

it('maps the pagination values from the fixture', function (): void {
    $page = (new RankingsMapper)->map(checkpointD2RankingsFixture());

    expect($page->pagination->page)->toBe(1);
    expect($page->pagination->pageSize)->toBe(3);
    expect($page->pagination->totalItems)->toBe(3);
    expect($page->pagination->hasNext)->toBeFalse();
});

it('maps all five fields of the first and last entry from the fixture', function (): void {
    $page = (new RankingsMapper)->map(checkpointD2RankingsFixture());

    $first = $page->entries[0];
    expect($first->cid)->toBe('100001');
    expect($first->username)->toBe('trader_001');
    expect($first->type)->toBe('trader');
    expect($first->subType)->toBe('pi-certified');
    expect($first->copiers)->toBe(5000);

    $last = $page->entries[2];
    expect($last->cid)->toBe('100003');
    expect($last->username)->toBe('smart_portfolio_001');
    expect($last->type)->toBe('smart-portfolio');
    expect($last->subType)->toBe('market');
    expect($last->copiers)->toBe(3000);
});

it('normalizes the fixture\'s integer cid to a string', function (): void {
    $page = (new RankingsMapper)->map(checkpointD2RankingsFixture());

    expect($page->entries[0]->cid)->toBeString()->toBe('100001');
});

it('preserves the fixture\'s entry order in the output', function (): void {
    $page = (new RankingsMapper)->map(checkpointD2RankingsFixture());

    $usernames = array_map(fn ($entry) => $entry->username, $page->entries);

    expect($usernames)->toBe(['trader_001', 'trader_002', 'smart_portfolio_001']);
});

// --- Required fields ---------------------------------------------------

it('rejects a missing results key', function (): void {
    $fixture = checkpointD2RankingsFixture();
    unset($fixture['results']);

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('results');
});

it('rejects a missing pagination key', function (): void {
    $fixture = checkpointD2RankingsFixture();
    unset($fixture['pagination']);

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('pagination');
});

it('rejects a result item missing a required field', function (string $missingField): void {
    $fixture = checkpointD2RankingsFixture();
    unset($fixture['results'][0][$missingField]);

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe("results[0].{$missingField}");
})->with(['cid', 'username', 'type', 'subType', 'copiers']);

it('rejects pagination missing a required field', function (string $missingField): void {
    $fixture = checkpointD2RankingsFixture();
    unset($fixture['pagination'][$missingField]);

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe("pagination.{$missingField}");
})->with(['page', 'pageSize', 'totalItems', 'hasNext']);

// --- Primitive type errors -----------------------------------------------

it('rejects a scalar results value', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'] = 'not-an-array';

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('results');
});

it('rejects a scalar pagination value', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination'] = 'not-an-array';

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('pagination');
});

it('rejects a scalar result item', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0] = 'not-an-array';

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('results[0]');
});

it('rejects a cid of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['cid'] = $wrongValue;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('results[0].cid');
})->with([
    'float' => [1.5],
    'null' => [null],
    'bool' => [true],
    'array' => [[1]],
]);

it('rejects a username/type/subType of an invalid primitive type', function (string $field, mixed $wrongValue): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0][$field] = $wrongValue;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe("results[0].{$field}");
})->with([
    'username int' => ['username', 123],
    'username float' => ['username', 1.5],
    'username bool' => ['username', true],
    'username null' => ['username', null],
    'username array' => ['username', ['a']],
    'type int' => ['type', 123],
    'subType int' => ['subType', 123],
]);

it('rejects a copiers of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['copiers'] = $wrongValue;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('results[0].copiers');
})->with([
    'float' => [1.5],
    'numeric string' => ['5000'],
]);

it('rejects pagination int fields of an invalid primitive type', function (string $field, mixed $wrongValue): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination'][$field] = $wrongValue;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe("pagination.{$field}");
})->with([
    'page string' => ['page', '1'],
    'page float' => ['page', 1.5],
    'pageSize string' => ['pageSize', '3'],
    'pageSize float' => ['pageSize', 3.5],
    'totalItems string' => ['totalItems', '3'],
    'totalItems float' => ['totalItems', 3.5],
]);

it('rejects a hasNext of an invalid primitive type', function (mixed $wrongValue): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination']['hasNext'] = $wrongValue;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('pagination.hasNext');
})->with([
    'int' => [1],
    'string' => ['false'],
]);

// --- Shape errors ----------------------------------------------------------

it('rejects an associative array for results', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'] = ['first' => $fixture['results'][0]];

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('results');
});

it('rejects a non-empty list for pagination', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination'] = [1, 2, 3];

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('pagination');
});

it('rejects a non-empty list for a result item', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0] = ['a', 'b'];

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::UnexpectedShape);
    expect($exception->fieldPath)->toBe('results[0]');
});

it('treats an empty pagination object as missing its first required field', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination'] = [];

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('pagination.page');
});

it('treats an empty result item as missing its first required field', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0] = [];

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::MissingRequiredField);
    expect($exception->fieldPath)->toBe('results[0].cid');
});

// --- Invalid values ----------------------------------------------------

it('rejects a blank cid', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['cid'] = '   ';

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('results[0].cid');
});

it('rejects a cid containing a NUL byte', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['cid'] = "100\x00001";

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('results[0].cid');
});

it('rejects a blank username/type/subType', function (string $field): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0][$field] = '   ';

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe("results[0].{$field}");
})->with(['username', 'type', 'subType']);

it('rejects a username/type/subType containing a NUL byte', function (string $field): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0][$field] = "trader\x00001";

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe("results[0].{$field}");
})->with(['username', 'type', 'subType']);

it('rejects a negative copiers value', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['copiers'] = -1;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('results[0].copiers');
});

it('rejects a page of 0', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination']['page'] = 0;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('pagination.page');
});

it('rejects a negative pageSize', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination']['pageSize'] = -1;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('pagination.pageSize');
});

it('rejects a negative totalItems', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination']['totalItems'] = -1;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidValue);
    expect($exception->fieldPath)->toBe('pagination.totalItems');
});

// --- Identifier behavior -----------------------------------------------

it('normalizes an int cid to a string', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['cid'] = 999999;

    $page = (new RankingsMapper)->map($fixture);

    expect($page->entries[0]->cid)->toBeString()->toBe('999999');
});

it('keeps a numeric string cid as the same string', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['cid'] = '100001';

    $page = (new RankingsMapper)->map($fixture);

    expect($page->entries[0]->cid)->toBe('100001');
});

it('trims whitespace around a string cid', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['cid'] = '  100001  ';

    $page = (new RankingsMapper)->map($fixture);

    expect($page->entries[0]->cid)->toBe('100001');
});

it('rejects an int username instead of silently converting it to a string', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['username'] = 100001;

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->reason)->toBe(EtoroMappingErrorReason::InvalidPrimitiveType);
    expect($exception->fieldPath)->toBe('results[0].username');
});

// --- Ordering and duplicates ------------------------------------------------

it('preserves a permuted results order exactly, without sorting', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'] = array_reverse($fixture['results']);

    $page = (new RankingsMapper)->map($fixture);

    $usernames = array_map(fn ($entry) => $entry->username, $page->entries);

    expect($usernames)->toBe(['smart_portfolio_001', 'trader_002', 'trader_001']);
});

it('keeps both entries when cid is duplicated, without deduplication', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][1]['cid'] = $fixture['results'][0]['cid'];

    $page = (new RankingsMapper)->map($fixture);

    expect($page->entries)->toHaveCount(3);
    expect($page->entries[0]->cid)->toBe($page->entries[1]->cid);
});

it('keeps both entries when username is duplicated, without deduplication', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][1]['username'] = $fixture['results'][0]['username'];

    $page = (new RankingsMapper)->map($fixture);

    expect($page->entries)->toHaveCount(3);
    expect($page->entries[0]->username)->toBe($page->entries[1]->username);
});

// --- Unknown fields ----------------------------------------------------

it('ignores an unknown top-level field', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['unknownTopLevelField'] = 'unexpected';

    expect(fn () => (new RankingsMapper)->map($fixture))->not->toThrow(Throwable::class);
});

it('ignores an unknown pagination field', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['pagination']['unknownField'] = 'unexpected';

    expect(fn () => (new RankingsMapper)->map($fixture))->not->toThrow(Throwable::class);
});

it('ignores deferred rankings fields within an entry', function (string $field): void {
    $fixture = checkpointD2RankingsFixture();

    expect(array_key_exists($field, $fixture['results'][0]))->toBeTrue();
    expect(fn () => (new RankingsMapper)->map($fixture))->not->toThrow(Throwable::class);
})->with(['gain', 'avatarUrl', 'fullName', 'firstActivity', 'industryTags']);

// --- Other -----------------------------------------------------------------

it('accepts an empty results list', function (): void {
    $page = (new RankingsMapper)->map([
        'results' => [],
        'pagination' => ['page' => 1, 'pageSize' => 0, 'totalItems' => 0, 'hasNext' => false],
    ]);

    expect($page->entries)->toBe([]);
});

it('does not mutate the input payload', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $originalJson = json_encode($fixture);

    (new RankingsMapper)->map($fixture);

    expect(json_encode($fixture))->toBe($originalJson);
});

it('two calls with the same payload produce an identical result', function (): void {
    $fixture = checkpointD2RankingsFixture();

    $first = (new RankingsMapper)->map($fixture);
    $second = (new RankingsMapper)->map($fixture);

    $extract = fn (RankingPage $page) => [
        array_map(fn ($e) => [$e->cid, $e->username, $e->type, $e->subType, $e->copiers], $page->entries),
        [$page->pagination->page, $page->pagination->pageSize, $page->pagination->totalItems, $page->pagination->hasNext],
    ];

    expect($extract($second))->toBe($extract($first));
});

it('does not include a mutated sentinel value in mapper exception messages', function (): void {
    $fixture = checkpointD2RankingsFixture();
    $fixture['results'][0]['username'] = "sentinel-marker-do-not-leak\x00";

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->getMessage())->not->toContain('sentinel-marker-do-not-leak');
});

it('does not include PII or URL values from ignored fields in mapper exception messages', function (): void {
    $fixture = checkpointD2RankingsFixture();
    // Force an unrelated failure (missing copiers) while a PII/URL-bearing
    // field is still present elsewhere in the same item, proving the
    // mapper never echoes ignored field content into any exception.
    unset($fixture['results'][0]['copiers']);

    $exception = expectCheckpointD2RankingsMappingException(fn () => (new RankingsMapper)->map($fixture));

    expect($exception->getMessage())->not->toContain($fixture['results'][0]['fullName']);
    expect($exception->getMessage())->not->toContain($fixture['results'][0]['avatarUrl']);
});
