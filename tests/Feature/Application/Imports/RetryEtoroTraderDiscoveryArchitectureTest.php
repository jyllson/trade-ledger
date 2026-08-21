<?php

use App\Application\Imports\DiscoverEtoroTraders;
use App\Application\Imports\DiscoverEtoroTradersResult;
use App\Application\Imports\RetryEtoroTraderDiscovery;
use App\Models\ImportRun;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint H1 architectural guarantees for the manual discovery retry use
 * case: it must depend only on DiscoverEtoroTraders (plus models/value
 * objects), never touch EtoroClient, the Laravel HTTP client, config/env,
 * Storage/Log/DB/Queue, Filament, or Livewire directly itself, and expose
 * canRetry()/handle() as a matched pair sharing one private eligibility
 * gate.
 */
function checkpointH1RetryDiscoveryCodeWithoutComments(ReflectionClass $reflection): string
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

it('RetryEtoroTraderDiscovery is final and not readonly', function (): void {
    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeFalse();
});

it('RetryEtoroTraderDiscovery exposes exactly two public behavior methods: handle and canRetry', function (): void {
    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === RetryEtoroTraderDiscovery::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values()
        ->sort()
        ->values();

    expect($publicMethods->all())->toBe(['canRetry', 'handle']);
});

it('handle() and canRetry() both accept exactly one ImportRun argument', function (): void {
    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);

    foreach (['handle', 'canRetry'] as $methodName) {
        $method = $reflection->getMethod($methodName);
        $parameters = $method->getParameters();

        expect($parameters)->toHaveCount(1);
        expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
        expect($parameters[0]->getType()->getName())->toBe(ImportRun::class);
    }
});

it('handle() returns DiscoverEtoroTradersResult and canRetry() returns bool', function (): void {
    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);

    $handleReturn = $reflection->getMethod('handle')->getReturnType();
    expect($handleReturn)->toBeInstanceOf(ReflectionNamedType::class);
    expect($handleReturn->getName())->toBe(DiscoverEtoroTradersResult::class);

    $canRetryReturn = $reflection->getMethod('canRetry')->getReturnType();
    expect($canRetryReturn)->toBeInstanceOf(ReflectionNamedType::class);
    expect($canRetryReturn->getName())->toBe('bool');
});

it('constructor dependency type is exactly DiscoverEtoroTraders, private and readonly', function (): void {
    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(DiscoverEtoroTraders::class);
    expect($parameters[0]->isPromoted())->toBeTrue();

    $properties = $reflection->getProperties();

    expect($properties)->toHaveCount(1);
    expect($properties[0]->isPrivate())->toBeTrue();
    expect($properties[0]->isReadOnly())->toBeTrue();
});

it('handle() and canRetry() both route through the exact same private assertEligible() gate', function (): void {
    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);
    $code = checkpointH1RetryDiscoveryCodeWithoutComments($reflection);

    expect(substr_count($code, '$this->assertEligible('))->toBe(2);
});

it('RetryEtoroTraderDiscovery does not use EtoroClient, the Laravel HTTP client, config/env, Storage/Log/DB/Queue, Filament, or Livewire directly', function (): void {
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
        'Filament',
        'Livewire',
        'GuzzleHttp',
    ];

    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);
    $code = checkpointH1RetryDiscoveryCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('canRetry() never saves/deletes the ImportRun it is given', function (): void {
    $reflection = new ReflectionClass(RetryEtoroTraderDiscovery::class);
    $code = checkpointH1RetryDiscoveryCodeWithoutComments($reflection);

    foreach (['->save(', '->delete(', '->forceFill(', '::create(', '->update('] as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('App\\Application\\Imports\\RetryEtoroTraderDiscovery does not depend on App\\Console or App\\Filament', function (): void {
    $code = checkpointH1RetryDiscoveryCodeWithoutComments(new ReflectionClass(RetryEtoroTraderDiscovery::class));

    expect($code)->not->toContain('App\\Console');
    expect($code)->not->toContain('App\\Filament');
});
