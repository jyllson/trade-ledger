<?php

use App\Application\Traders\FindStoredTraderByUsername;
use App\Application\Traders\TraderUsername;
use App\Models\Trader;

it('finds an existing trader by exact username', function (): void {
    $trader = Trader::factory()->create(['username' => 'trader_001']);

    $found = (new FindStoredTraderByUsername)->handle(new TraderUsername('trader_001'));

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($trader->id);
});

it('returns null when no trader with that username exists', function (): void {
    $found = (new FindStoredTraderByUsername)->handle(new TraderUsername('nobody_here'));

    expect($found)->toBeNull();
});

it('fails closed on a case-variant query: a differently-cased username never matches, deterministic on SQLite', function (): void {
    Trader::factory()->create(['username' => 'Trader_001']);

    $found = (new FindStoredTraderByUsername)->handle(new TraderUsername('trader_001'));

    expect($found)->toBeNull();
});

it('trims the query username before matching, via the shared TraderUsername contract', function (): void {
    Trader::factory()->create(['username' => 'trader_001']);

    $found = (new FindStoredTraderByUsername)->handle(new TraderUsername('  trader_001  '));

    expect($found)->not->toBeNull();
});

it('does not mutate the matched trader', function (): void {
    $trader = Trader::factory()->create(['username' => 'trader_001', 'copiers_count' => 42]);

    (new FindStoredTraderByUsername)->handle(new TraderUsername('trader_001'));

    expect($trader->refresh()->copiers_count)->toBe(42);
});

it('is architecturally free of HTTP/network dependencies and always uses a post-query PHP-exact (===) guard — the load-bearing check on a case-insensitive MySQL collation', function (): void {
    $reflection = new ReflectionClass(FindStoredTraderByUsername::class);
    $source = file_get_contents($reflection->getFileName());

    foreach (['Illuminate\\Support\\Facades\\Http', 'Http::', 'GuzzleHttp', 'EtoroClient'] as $needle) {
        expect($source)->not->toContain($needle);
    }

    // The exact structural guard this method's SQLite-deterministic
    // behavior above relies on for MySQL: a strict === comparison against
    // the already-queried row, never trusting the WHERE clause's own
    // collation to have been exact.
    expect($source)->toContain('$trader->username === $username->value');
});
