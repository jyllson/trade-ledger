<?php

use App\Application\Traders\EvaluateTraderProfileFreshness;
use App\Application\Traders\ProfileFreshness;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint H2 architectural guarantee: the profile freshness rule is
 * computed in exactly one place, with no Filament/HTTP/DB/config/env
 * dependency, so the UI layer can never duplicate or drift from it.
 */
function checkpointH2FreshnessCodeWithoutComments(ReflectionClass $reflection): string
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

it('EvaluateTraderProfileFreshness is final and not readonly', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderProfileFreshness::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeFalse();
});

it('ProfileFreshness is a backed string enum with exactly the three documented cases', function (): void {
    expect(array_map(fn (ProfileFreshness $case): string => $case->value, ProfileFreshness::cases()))
        ->toBe(['never_synced', 'fresh', 'stale']);
});

it('EvaluateTraderProfileFreshness exposes exactly one public behavior method: handle', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderProfileFreshness::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === EvaluateTraderProfileFreshness::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values();

    expect($publicMethods->all())->toBe(['handle']);
});

it('EvaluateTraderProfileFreshness has no constructor dependencies', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderProfileFreshness::class);

    expect($reflection->getConstructor())->toBeNull();
});

it('EvaluateTraderProfileFreshness does not use Filament, HTTP, config/env, Storage/Log/DB/Queue', function (): void {
    $forbiddenSubstrings = [
        'Filament',
        'Livewire',
        'Illuminate\\Support\\Facades\\Http',
        'Http::',
        'config(',
        'env(',
        'Storage::',
        'Log::',
        'DB::',
        'Queue::',
        'GuzzleHttp',
    ];

    $reflection = new ReflectionClass(EvaluateTraderProfileFreshness::class);
    $code = checkpointH2FreshnessCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});
