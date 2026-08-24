<?php

use App\Application\Traders\ChangeTraderStatus;
use App\Models\Trader;
use App\Models\TraderStatus;

it('changes a trader status and returns the refreshed model', function (TraderStatus $from, TraderStatus $to): void {
    $trader = Trader::factory()->create(['status' => $from]);

    $result = (new ChangeTraderStatus)->handle($trader, $to);

    expect($result->id)->toBe($trader->id)
        ->and($result->status)->toBe($to);

    expect(Trader::query()->findOrFail($trader->id)->status)->toBe($to);
})->with([
    'candidate -> watched' => [TraderStatus::Candidate, TraderStatus::Watched],
    'candidate -> ignored' => [TraderStatus::Candidate, TraderStatus::Ignored],
    'watched -> candidate' => [TraderStatus::Watched, TraderStatus::Candidate],
    'watched -> ignored' => [TraderStatus::Watched, TraderStatus::Ignored],
    'ignored -> candidate' => [TraderStatus::Ignored, TraderStatus::Candidate],
    'ignored -> watched' => [TraderStatus::Ignored, TraderStatus::Watched],
    'candidate -> candidate (same-state no-op)' => [TraderStatus::Candidate, TraderStatus::Candidate],
    'watched -> watched (same-state no-op)' => [TraderStatus::Watched, TraderStatus::Watched],
    'ignored -> ignored (same-state no-op)' => [TraderStatus::Ignored, TraderStatus::Ignored],
]);

it('does not mutate any other trader field', function (): void {
    $trader = Trader::factory()->create([
        'status' => TraderStatus::Candidate,
        'external_cid' => '100001',
        'username' => 'trader_001',
        'copiers_count' => 4242,
    ]);

    (new ChangeTraderStatus)->handle($trader, TraderStatus::Watched);

    $fromDatabase = Trader::query()->findOrFail($trader->id);

    expect($fromDatabase->external_cid)->toBe('100001')
        ->and($fromDatabase->username)->toBe('trader_001')
        ->and($fromDatabase->copiers_count)->toBe(4242);
});

it('propagates a persistence failure and leaves the stored status untouched', function (): void {
    $trader = Trader::factory()->create(['status' => TraderStatus::Candidate]);

    $originalDispatcher = Trader::getEventDispatcher();
    Trader::setEventDispatcher(clone $originalDispatcher);

    Trader::saving(function (): void {
        throw new RuntimeException('Simulated trader status save failure for test purposes.');
    });

    try {
        expect(fn () => (new ChangeTraderStatus)->handle($trader, TraderStatus::Watched))
            ->toThrow(RuntimeException::class, 'Simulated trader status save failure for test purposes.');
    } finally {
        Trader::setEventDispatcher($originalDispatcher);
    }

    expect(Trader::query()->findOrFail($trader->id)->status)->toBe(TraderStatus::Candidate);
});
