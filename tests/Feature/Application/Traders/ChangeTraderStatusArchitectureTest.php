<?php

use App\Application\Traders\ChangeTraderStatus;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint H1 architectural guarantees for the trader status transition
 * use case: presentation/network independent, no ImportRun bookkeeping, and
 * a narrow single-behavior surface.
 */
function checkpointH1ChangeTraderStatusCodeWithoutComments(ReflectionClass $reflection): string
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

it('ChangeTraderStatus is final and not readonly', function (): void {
    $reflection = new ReflectionClass(ChangeTraderStatus::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeFalse();
});

it('ChangeTraderStatus exposes exactly one public behavior method: handle', function (): void {
    $reflection = new ReflectionClass(ChangeTraderStatus::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === ChangeTraderStatus::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values();

    expect($publicMethods->all())->toBe(['handle']);
});

it('handle() has the exact input and return type contract', function (): void {
    $reflection = new ReflectionClass(ChangeTraderStatus::class);
    $method = $reflection->getMethod('handle');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(2);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(Trader::class);
    expect($parameters[1]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[1]->getType()->getName())->toBe(TraderStatus::class);

    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe(Trader::class);
});

it('ChangeTraderStatus has no constructor dependencies', function (): void {
    $reflection = new ReflectionClass(ChangeTraderStatus::class);

    expect($reflection->getConstructor())->toBeNull();
});

it('ChangeTraderStatus does not use HTTP, config/env, Storage/Log/DB/Queue, Filament, Livewire, or ImportRun', function (): void {
    $forbiddenSubstrings = [
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
        'ImportRun',
        'GuzzleHttp',
    ];

    $reflection = new ReflectionClass(ChangeTraderStatus::class);
    $code = checkpointH1ChangeTraderStatusCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('App\\Application\\Traders\\ChangeTraderStatus does not depend on App\\Console or App\\Filament', function (): void {
    $code = checkpointH1ChangeTraderStatusCodeWithoutComments(new ReflectionClass(ChangeTraderStatus::class));

    expect($code)->not->toContain('App\\Console');
    expect($code)->not->toContain('App\\Filament');
});
