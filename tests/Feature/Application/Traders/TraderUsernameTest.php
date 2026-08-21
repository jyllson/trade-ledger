<?php

use App\Application\Traders\TraderUsername;

it('trims surrounding whitespace', function (): void {
    $username = new TraderUsername("  trader_001 \t\n");

    expect($username->value)->toBe('trader_001');
});

it('rejects a blank (post-trim) username', function (): void {
    expect(fn () => new TraderUsername('   '))->toThrow(InvalidArgumentException::class);
});

it('rejects an empty string', function (): void {
    expect(fn () => new TraderUsername(''))->toThrow(InvalidArgumentException::class);
});

it('rejects a username containing a NUL byte', function (): void {
    expect(fn () => new TraderUsername("trader\0_001"))->toThrow(InvalidArgumentException::class);
});

it('rejects a whitespace-and-NUL-only username', function (): void {
    expect(fn () => new TraderUsername(" \0 "))->toThrow(InvalidArgumentException::class);
});

it('preserves exact case and internal content — no case-folding, no partial normalization', function (): void {
    $username = new TraderUsername('  Trader_Mixed_Case  ');

    expect($username->value)->toBe('Trader_Mixed_Case');
});

it('is immutable/readonly', function (): void {
    $reflection = new ReflectionClass(TraderUsername::class);

    expect($reflection->isReadOnly())->toBeTrue();
    expect($reflection->isFinal())->toBeTrue();
});
