<?php

use App\Application\Traders\FindStoredTraderByUsername;
use App\Application\Traders\LookupEtoroTraderProfile;
use App\Application\Traders\LookupEtoroTraderProfileResult;
use App\Application\Traders\LookupEtoroTraderProfileStopReason;
use App\Application\Traders\TraderUsername;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\TraderProfileMapper;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint G architectural guarantees for the remote trader profile
 * lookup use case: it must depend only on EtoroClient, TraderProfileMapper
 * and FindStoredTraderByUsername; never touch the Laravel HTTP client,
 * config/env, or Storage/Log/Queue/Filament/Livewire directly itself; never
 * call any EtoroClient endpoint method other than userProfile(); and its
 * only DB:: usage may be the single DB::transaction() wrapping the
 * existing-Trader enrichment write.
 */
function checkpointGLookupProfileCodeWithoutComments(ReflectionClass $reflection): string
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

it('LookupEtoroTraderProfile is final and not readonly', function (): void {
    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeFalse();
});

it('LookupEtoroTraderProfileResult is final and readonly, and LookupEtoroTraderProfileStopReason is a backed enum', function (): void {
    $resultReflection = new ReflectionClass(LookupEtoroTraderProfileResult::class);

    expect($resultReflection->isFinal())->toBeTrue();
    expect($resultReflection->isReadOnly())->toBeTrue();

    expect(enum_exists(LookupEtoroTraderProfileStopReason::class))->toBeTrue();
    expect(LookupEtoroTraderProfileStopReason::Completed->value)->toBeString();
});

it('LookupEtoroTraderProfile exposes exactly one public behavior method: handle', function (): void {
    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LookupEtoroTraderProfile::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values();

    expect($publicMethods->all())->toBe(['handle']);
});

it('handle() has the exact input and return type contract', function (): void {
    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);
    $method = $reflection->getMethod('handle');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(TraderUsername::class);

    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe(LookupEtoroTraderProfileResult::class);
});

it('constructor dependency types are exactly EtoroClient, TraderProfileMapper, FindStoredTraderByUsername, all private and readonly', function (): void {
    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(3);

    $expectedTypes = [EtoroClient::class, TraderProfileMapper::class, FindStoredTraderByUsername::class];

    foreach ($parameters as $index => $parameter) {
        expect($parameter->getType())->toBeInstanceOf(ReflectionNamedType::class);
        expect($parameter->getType()->getName())->toBe($expectedTypes[$index]);
        expect($parameter->isPromoted())->toBeTrue();
    }

    $properties = $reflection->getProperties();

    expect($properties)->toHaveCount(3);

    foreach ($properties as $property) {
        expect($property->isPrivate())->toBeTrue();
        expect($property->isReadOnly())->toBeTrue();
    }
});

it('LookupEtoroTraderProfile does not use the Laravel HTTP client, config/env, Storage/Log/Queue, Filament, or Livewire directly', function (): void {
    $forbiddenSubstrings = [
        'Illuminate\\Support\\Facades\\Http',
        'Illuminate\\Http\\Client',
        'Http::',
        'config(',
        'env(',
        'Storage::',
        'Log::',
        'Queue::',
        'dispatch(',
        'Filament',
        'Livewire',
        'curl_',
        'fsockopen',
        'stream_socket_client',
        'GuzzleHttp',
        'Symfony\\Contracts\\HttpClient',
        'Symfony\\Component\\HttpClient',
        'Psr\\Http\\Client',
        'socket_create',
        'socket_connect',
    ];

    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);
    $code = checkpointGLookupProfileCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('LookupEtoroTraderProfile\'s only DB:: usage is the single DB::transaction() wrapping the existing-Trader enrichment write', function (): void {
    $forbiddenDbCalls = [
        'DB::table(',
        'DB::select(',
        'DB::statement(',
        'DB::raw(',
        'DB::insert(',
        'DB::update(',
        'DB::delete(',
        'DB::connection(',
        'DB::unprepared(',
    ];

    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);
    $code = checkpointGLookupProfileCodeWithoutComments($reflection);

    foreach ($forbiddenDbCalls as $needle) {
        expect($code)->not->toContain($needle);
    }

    expect(substr_count($code, 'DB::'))->toBe(1);
    expect($code)->toContain('DB::transaction(');
});

it('LookupEtoroTraderProfile calls only userProfile(), never another EtoroClient endpoint method', function (): void {
    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);
    $code = checkpointGLookupProfileCodeWithoutComments($reflection);

    $forbiddenCalls = [
        'authenticatedUser(',
        'rankings(',
        'userPerformance(',
        'userLivePortfolio(',
        'accountPnl(',
    ];

    foreach ($forbiddenCalls as $needle) {
        expect($code)->not->toContain($needle);
    }

    expect($code)->toContain('->userProfile(');
});

it('LookupEtoroTraderProfile catches only the four documented typed exceptions plus a best-effort Throwable rethrow', function (): void {
    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);
    $code = checkpointGLookupProfileCodeWithoutComments($reflection);

    foreach (['EtoroConfigurationException', 'EtoroRequestException', 'EtoroUnexpectedResponseException', 'EtoroMappingException'] as $needle) {
        expect($code)->toContain('catch ('.$needle);
    }

    expect($code)->toContain('catch (Throwable $exception)');
    expect($code)->toContain('throw $exception;');
});

it('LookupEtoroTraderProfile never creates a Trader and never compares profile_gcid to external_cid', function (): void {
    $reflection = new ReflectionClass(LookupEtoroTraderProfile::class);
    $code = checkpointGLookupProfileCodeWithoutComments($reflection);

    foreach (['Trader::create(', 'Trader::factory(', 'Trader::query()->create(', 'external_cid'] as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('App\\Application\\Traders does not depend on App\\Console or App\\Filament', function (): void {
    foreach ([
        LookupEtoroTraderProfile::class,
        LookupEtoroTraderProfileResult::class,
        LookupEtoroTraderProfileStopReason::class,
        FindStoredTraderByUsername::class,
        TraderUsername::class,
    ] as $class) {
        $code = checkpointGLookupProfileCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
        expect($code)->not->toContain('App\\Filament');
    }
});
