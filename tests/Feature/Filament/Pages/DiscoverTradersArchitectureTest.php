<?php

use App\Filament\Pages\DiscoverTraders;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint H2 architectural guarantees for the Discover Traders page:
 * no direct EtoroClient/HTTP/DB/config/env/Storage/Log/Queue dependency,
 * and every eToro-touching call happens only through DiscoverEtoroTraders
 * / LookupEtoroTraderProfile inside an explicit action() closure — never
 * during mount/render (see DiscoverTradersTest's "renders with NO HTTP
 * call at all" for the behavioral proof of that).
 */
function checkpointH2DiscoverTradersCodeWithoutComments(): string
{
    $reflection = new ReflectionClass(DiscoverTraders::class);
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

it('DiscoverTraders does not use EtoroClient, HTTP, config/env, Storage/Log/DB/Queue directly', function (): void {
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

    $code = checkpointH2DiscoverTradersCodeWithoutComments();

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('DiscoverTraders has no mount() method — nothing can run before an explicit action submission', function (): void {
    $reflection = new ReflectionClass(DiscoverTraders::class);

    expect($reflection->hasMethod('mount'))->toBeFalse();
});

it('DiscoverTraders never stores a raw DiscoverEtoroTradersResult/LookupEtoroTraderProfileResult as public Livewire state, only a sanitized array snapshot', function (): void {
    $reflection = new ReflectionClass(DiscoverTraders::class);

    $ownProperties = collect($reflection->getProperties(ReflectionProperty::IS_PUBLIC))
        ->filter(fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === DiscoverTraders::class);

    expect($ownProperties)->not->toBeEmpty();

    foreach ($ownProperties as $property) {
        $type = $property->getType();

        expect($type)->not->toBeNull();
        expect((string) $type)->toContain('array');
    }
});

it('DiscoverTraders only ever calls DiscoverEtoroTraders::handle() and LookupEtoroTraderProfile::handle(), each exactly once', function (): void {
    $code = checkpointH2DiscoverTradersCodeWithoutComments();

    expect(substr_count($code, 'DiscoverEtoroTraders::class)->handle('))->toBe(1);
    expect(substr_count($code, 'LookupEtoroTraderProfile::class)->handle('))->toBe(1);
});

it('App\\Filament\\Pages\\DiscoverTraders does not depend on App\\Console', function (): void {
    $code = checkpointH2DiscoverTradersCodeWithoutComments();

    expect($code)->not->toContain('App\\Console');
});
