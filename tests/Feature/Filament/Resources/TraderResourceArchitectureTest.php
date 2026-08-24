<?php

use App\Filament\Resources\Traders\Pages\ListTraders;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Filament\Resources\Traders\Schemas\TraderInfolist;
use App\Filament\Resources\Traders\Tables\TradersTable;
use App\Filament\Resources\Traders\TraderResource;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint H2 architectural guarantees for every App\Filament\Resources\
 * Traders class: no direct EtoroClient/HTTP/DB/config/env/Storage/Log/
 * Queue dependency, no direct Trader status/profile mutation outside the
 * two documented application services, and no locally duplicated
 * freshness threshold logic.
 */
function checkpointH2TraderFilamentClasses(): array
{
    return [
        TraderResource::class,
        ListTraders::class,
        ViewTrader::class,
        TraderInfolist::class,
        TradersTable::class,
    ];
}

function checkpointH2CodeWithoutComments(ReflectionClass $reflection): string
{
    $source = File::get($reflection->getFileName());
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('no App\\Filament\\Resources\\Traders class uses EtoroClient, HTTP, config/env, Storage/Log/DB/Queue directly', function (string $class) {
    $forbiddenSubstrings = [
        'EtoroClient',
        'Illuminate\\Support\\Facades\\Http',
        'Illuminate\\Http\\Client',
        'Http::',
        'config(',
        'env(',
        'Storage::',
        'Log::',
        'DB::',
        'Queue::',
        'dispatch(',
        'GuzzleHttp',
    ];

    $code = checkpointH2CodeWithoutComments(new ReflectionClass($class));

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
})->with(checkpointH2TraderFilamentClasses());

it('no App\\Filament\\Resources\\Traders class saves/updates a Trader directly — only ChangeTraderStatus and LookupEtoroTraderProfile ever write', function (string $class) {
    $forbiddenSubstrings = [
        'Trader::create(',
        '->status = ',
        'Trader::query()->update(',
    ];

    $code = checkpointH2CodeWithoutComments(new ReflectionClass($class));

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
})->with(checkpointH2TraderFilamentClasses());

it('TradersTable only ever calls ChangeTraderStatus::handle() and LookupEtoroTraderProfile::handle() to mutate a Trader, never ->save()/->forceFill() on the record itself', function (): void {
    $code = checkpointH2CodeWithoutComments(new ReflectionClass(TradersTable::class));

    expect($code)->not->toContain('$record->save(')
        ->not->toContain('$record->forceFill(')
        ->not->toContain('$record->update(');

    expect($code)->toContain('ChangeTraderStatus::class)->handle(')
        ->toContain('LookupEtoroTraderProfile::class)->handle(');
});

it('no App\\Filament\\Resources\\Traders class duplicates the 24-hour freshness threshold — only EvaluateTraderProfileFreshness computes it', function (string $class) {
    $forbiddenSubstrings = [
        '86400',
        'diffInHours',
        'diffInSeconds',
        'diffInDays',
        'subHours(24)',
        'subDay(',
    ];

    $code = checkpointH2CodeWithoutComments(new ReflectionClass($class));

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
})->with(checkpointH2TraderFilamentClasses());

it('TradersTable and TraderInfolist compute freshness only through EvaluateTraderProfileFreshness', function (string $class) {
    $code = checkpointH2CodeWithoutComments(new ReflectionClass($class));

    expect($code)->toContain('EvaluateTraderProfileFreshness::class)->handle(');
})->with([
    TradersTable::class,
    TraderInfolist::class,
]);

it('TraderResource has no Create or Edit page and canCreate() is false', function (): void {
    expect(TraderResource::hasPage('create'))->toBeFalse();
    expect(TraderResource::hasPage('edit'))->toBeFalse();
    expect(TraderResource::canCreate())->toBeFalse();
});
