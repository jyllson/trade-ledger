<?php

use App\Application\Imports\DiscoverEtoroTradersRequest;

it('trims period and rejects blank input', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: '  lastYear  ', startPage: 1, maxPages: 1);

    expect($request->period)->toBe('lastYear');
});

it('rejects a blank period', function (): void {
    expect(fn () => new DiscoverEtoroTradersRequest(period: '   ', startPage: 1, maxPages: 1))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects startPage below 1', function (): void {
    expect(fn () => new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 0, maxPages: 1))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts startPage of exactly 1', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 1);

    expect($request->startPage)->toBe(1);
});

it('rejects maxPages below 1', function (): void {
    expect(fn () => new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 0))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects maxPages above the ceiling of 20', function (): void {
    expect(fn () => new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 21))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts maxPages of exactly 20', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 20);

    expect($request->maxPages)->toBe(20);
});

it('normalizes a blank sort/country to null after trimming', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 1, sort: '   ', country: "\t");

    expect($request->sort)->toBeNull()
        ->and($request->country)->toBeNull();
});

it('trims a non-blank sort/country', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 1, sort: '  -copiers  ', country: '  US  ');

    expect($request->sort)->toBe('-copiers')
        ->and($request->country)->toBe('US');
});

it('leaves a null sort/country as null', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 1);

    expect($request->sort)->toBeNull()
        ->and($request->country)->toBeNull();
});

it('rejects rankingQueryForPage(0) even though startPage is 1', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);

    expect(fn () => $request->rankingQueryForPage(0))->toThrow(InvalidArgumentException::class);
});

it('rejects a negative rankingQueryForPage() argument', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 1, maxPages: 5);

    expect(fn () => $request->rankingQueryForPage(-1))->toThrow(InvalidArgumentException::class);
});

it('rejects rankingQueryForPage() below the configured startPage', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 5, maxPages: 5);

    expect(fn () => $request->rankingQueryForPage(4))->toThrow(InvalidArgumentException::class);
});

it('accepts rankingQueryForPage() exactly at startPage', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 5, maxPages: 5);

    expect($request->rankingQueryForPage(5)->page)->toBe(5);
});

it('accepts rankingQueryForPage() above startPage', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 5, maxPages: 5);

    expect($request->rankingQueryForPage(9)->page)->toBe(9);
});

it('builds a deterministic RankingQuery for a given page with pageSize fixed at 20', function (): void {
    $request = new DiscoverEtoroTradersRequest(period: 'lastYear', startPage: 3, maxPages: 5, sort: '-copiers', country: 'US');

    $query = $request->rankingQueryForPage(7);

    expect($query->period)->toBe('lastYear')
        ->and($query->page)->toBe(7)
        ->and($query->pageSize)->toBe(20)
        ->and($query->sort)->toBe('-copiers')
        ->and($query->country)->toBe('US');
});
