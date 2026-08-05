<?php

use App\Etoro\Data\RankingEntry;
use App\Etoro\Data\RankingPage;
use App\Etoro\Data\RankingPagination;

function checkpointD2Entry(
    string $cid = '100001',
    string $username = 'trader_001',
    string $type = 'trader',
    string $subType = 'pi-certified',
    int $copiers = 5000,
): RankingEntry {
    return new RankingEntry(
        cid: $cid,
        username: $username,
        type: $type,
        subType: $subType,
        copiers: $copiers,
    );
}

// --- RankingEntry ------------------------------------------------------

it('RankingEntry accepts a valid construction', function (): void {
    $entry = checkpointD2Entry();

    expect($entry->cid)->toBe('100001');
    expect($entry->username)->toBe('trader_001');
    expect($entry->type)->toBe('trader');
    expect($entry->subType)->toBe('pi-certified');
    expect($entry->copiers)->toBe(5000);
});

it('RankingEntry allows cid values that merely look numeric, like "0" and "-1"', function (string $cid): void {
    $entry = checkpointD2Entry(cid: $cid);

    expect($entry->cid)->toBe($cid);
})->with([
    '"0"' => ['0'],
    '"-1"' => ['-1'],
]);

it('RankingEntry rejects a blank or whitespace-only cid', function (string $cid): void {
    expect(fn () => checkpointD2Entry(cid: $cid))->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'spaces only' => ['   '],
    'tabs and newlines' => ["\t\n"],
]);

it('RankingEntry rejects a cid containing a NUL byte', function (): void {
    expect(fn () => checkpointD2Entry(cid: "100\x00001"))->toThrow(InvalidArgumentException::class);
});

it('RankingEntry trims username, type, and subType', function (): void {
    $entry = checkpointD2Entry(
        username: '  trader_001  ',
        type: "\ttrader\t",
        subType: " pi-certified\n",
    );

    expect($entry->username)->toBe('trader_001');
    expect($entry->type)->toBe('trader');
    expect($entry->subType)->toBe('pi-certified');
});

it('RankingEntry rejects a blank or whitespace-only username/type/subType', function (string $field, string $blank): void {
    expect(fn () => checkpointD2Entry(...[$field => $blank]))->toThrow(InvalidArgumentException::class);
})->with([
    'blank username' => ['username', '   '],
    'blank type' => ['type', '   '],
    'blank subType' => ['subType', '   '],
]);

it('RankingEntry rejects a username/type/subType containing a NUL byte', function (string $field): void {
    expect(fn () => checkpointD2Entry(...[$field => "trader\x00001"]))->toThrow(InvalidArgumentException::class);
})->with([
    'username' => ['username'],
    'type' => ['type'],
    'subType' => ['subType'],
]);

it('RankingEntry rejects a negative copiers value', function (): void {
    expect(fn () => checkpointD2Entry(copiers: -1))->toThrow(InvalidArgumentException::class);
});

it('RankingEntry accepts a zero copiers value', function (): void {
    $entry = checkpointD2Entry(copiers: 0);

    expect($entry->copiers)->toBe(0);
});

it('RankingEntry is immutable', function (): void {
    $entry = checkpointD2Entry();

    $property = new ReflectionProperty($entry, 'cid');

    expect(fn () => $property->setValue($entry, '999'))->toThrow(Error::class);
    expect($entry->cid)->toBe('100001');
});

it('RankingEntry exposes exactly five public domain properties', function (): void {
    $propertyNames = collect((new ReflectionClass(RankingEntry::class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->map(fn (ReflectionProperty $property): string => $property->getName())
        ->sort()
        ->values()
        ->all();

    expect($propertyNames)->toBe(['cid', 'copiers', 'subType', 'type', 'username']);
});

it('RankingEntry does not carry any of the deferred rankings fields', function (): void {
    $forbiddenPropertyNames = [
        'rank',
        'fullName',
        'avatarUrl',
        'gain',
        'dailyGain',
        'annualizedReturn',
        'winRatio',
        'exposure',
        'riskScore',
        'aumValue',
        'firstActivity',
        'lastActivity',
        'industryTags',
        'sectorTags',
        'raw',
        'rawPayload',
        'extra',
    ];

    $propertyNames = collect((new ReflectionClass(RankingEntry::class))->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName());

    expect($propertyNames->intersect($forbiddenPropertyNames)->values()->all())->toBe([]);
});

// --- RankingPagination ------------------------------------------------------

it('RankingPagination accepts valid boundary values', function (): void {
    $pagination = new RankingPagination(page: 1, pageSize: 0, totalItems: 0, hasNext: false);

    expect($pagination->page)->toBe(1);
    expect($pagination->pageSize)->toBe(0);
    expect($pagination->totalItems)->toBe(0);
    expect($pagination->hasNext)->toBeFalse();
});

it('RankingPagination rejects a page of 0', function (): void {
    expect(fn () => new RankingPagination(page: 0, pageSize: 1, totalItems: 1, hasNext: false))
        ->toThrow(InvalidArgumentException::class);
});

it('RankingPagination rejects a negative page', function (): void {
    expect(fn () => new RankingPagination(page: -1, pageSize: 1, totalItems: 1, hasNext: false))
        ->toThrow(InvalidArgumentException::class);
});

it('RankingPagination rejects a negative pageSize', function (): void {
    expect(fn () => new RankingPagination(page: 1, pageSize: -1, totalItems: 1, hasNext: false))
        ->toThrow(InvalidArgumentException::class);
});

it('RankingPagination rejects a negative totalItems', function (): void {
    expect(fn () => new RankingPagination(page: 1, pageSize: 1, totalItems: -1, hasNext: false))
        ->toThrow(InvalidArgumentException::class);
});

it('RankingPagination is immutable', function (): void {
    $pagination = new RankingPagination(page: 1, pageSize: 1, totalItems: 1, hasNext: true);

    $property = new ReflectionProperty($pagination, 'page');

    expect(fn () => $property->setValue($pagination, 2))->toThrow(Error::class);
    expect($pagination->page)->toBe(1);
});

// --- RankingPage ------------------------------------------------------

function checkpointD2Pagination(): RankingPagination
{
    return new RankingPagination(page: 1, pageSize: 3, totalItems: 3, hasNext: false);
}

it('RankingPage accepts a valid entries list', function (): void {
    $page = new RankingPage(
        entries: [checkpointD2Entry(), checkpointD2Entry(cid: '100002', username: 'trader_002')],
        pagination: checkpointD2Pagination(),
    );

    expect($page->entries)->toHaveCount(2);
});

it('RankingPage accepts an empty entries list', function (): void {
    $page = new RankingPage(entries: [], pagination: checkpointD2Pagination());

    expect($page->entries)->toBe([]);
});

it('RankingPage rejects an associative array for entries', function (): void {
    expect(fn () => new RankingPage(
        entries: ['first' => checkpointD2Entry()],
        pagination: checkpointD2Pagination(),
    ))->toThrow(InvalidArgumentException::class);
});

it('RankingPage rejects an entries element of the wrong type', function (): void {
    expect(fn () => new RankingPage(
        entries: ['not an entry'],
        pagination: checkpointD2Pagination(),
    ))->toThrow(InvalidArgumentException::class);
});

it('RankingPage allows duplicate cid and username entries', function (): void {
    $duplicate1 = checkpointD2Entry(cid: '100001', username: 'trader_001');
    $duplicate2 = checkpointD2Entry(cid: '100001', username: 'trader_001');

    $page = new RankingPage(entries: [$duplicate1, $duplicate2], pagination: checkpointD2Pagination());

    expect($page->entries)->toHaveCount(2);
});

it('RankingPage preserves the given entries order', function (): void {
    $first = checkpointD2Entry(cid: '100003', username: 'smart_portfolio_001');
    $second = checkpointD2Entry(cid: '100001', username: 'trader_001');

    $page = new RankingPage(entries: [$first, $second], pagination: checkpointD2Pagination());

    expect($page->entries[0])->toBe($first);
    expect($page->entries[1])->toBe($second);
});

it('RankingPage is immutable', function (): void {
    $page = new RankingPage(entries: [], pagination: checkpointD2Pagination());

    $property = new ReflectionProperty($page, 'entries');

    expect(fn () => $property->setValue($page, [checkpointD2Entry()]))->toThrow(Error::class);
});
