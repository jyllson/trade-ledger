<?php

use App\Models\Instrument;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Trader;

it('builds a snapshot with ordered positions linked to instruments through the factories', function () {
    $instrument = Instrument::factory()->enriched()->create();
    $snapshot = PortfolioSnapshot::factory()->create();
    PortfolioPosition::factory()->for($snapshot, 'snapshot')->create(['position_index' => 1, 'instrument_id' => $instrument->id]);
    PortfolioPosition::factory()->for($snapshot, 'snapshot')->create(['position_index' => 0, 'take_profit_rate' => '12.5']);

    $positions = $snapshot->positions()->get();

    expect($positions->pluck('position_index')->all())->toBe([0, 1])
        ->and($positions->first()->take_profit_rate)->toBe('12.5000000000')
        ->and($positions->last()->instrument->is($instrument))->toBeTrue()
        ->and($instrument->portfolioPositions()->count())->toBe(1)
        ->and($instrument->asset_class)->toBe('Stocks')
        ->and($snapshot->trader)->toBeInstanceOf(Trader::class);
});

it('resolves the latest portfolio snapshot of a trader', function () {
    $trader = Trader::factory()->create();
    PortfolioSnapshot::factory()->for($trader)->create(['captured_at' => now()->subDay()]);
    $latest = PortfolioSnapshot::factory()->for($trader)->create(['captured_at' => now()]);

    expect($trader->latestPortfolioSnapshot->is($latest))->toBeTrue()
        ->and($trader->portfolioSnapshots()->count())->toBe(2);
});

it('cascades snapshot deletion to positions and nulls the instrument link on instrument deletion', function () {
    $instrument = Instrument::factory()->create();
    $position = PortfolioPosition::factory()->create(['instrument_id' => $instrument->id]);

    $instrument->delete();
    expect($position->refresh()->instrument_id)->toBeNull();

    $position->snapshot->delete();
    expect(PortfolioPosition::count())->toBe(0);
});
