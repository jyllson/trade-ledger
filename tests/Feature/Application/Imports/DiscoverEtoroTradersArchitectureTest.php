<?php

use App\Application\Imports\DiscoverEtoroTraders;
use App\Application\Imports\DiscoverEtoroTradersRequest;
use App\Application\Imports\DiscoverEtoroTradersResult;
use App\Application\Imports\ImportRankingPage;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\RankingsMapper;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint E architectural guarantees for the live multi-page ranking
 * discovery orchestrator: it must depend only on EtoroClient, RankingsMapper
 * and ImportRankingPage; never touch the Laravel HTTP client, config/env, or
 * Storage/Log/DB/Queue/Filament/Livewire directly itself; and never call any
 * EtoroClient endpoint method other than rankings().
 */
function checkpointEDiscoverTradersCodeWithoutComments(ReflectionClass $reflection): string
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

it('DiscoverEtoroTraders is final and not readonly', function (): void {
    $reflection = new ReflectionClass(DiscoverEtoroTraders::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeFalse();
});

it('DiscoverEtoroTradersRequest and DiscoverEtoroTradersResult are final and readonly', function (): void {
    foreach ([DiscoverEtoroTradersRequest::class, DiscoverEtoroTradersResult::class] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    }
});

it('DiscoverEtoroTraders exposes exactly one public behavior method: handle', function (): void {
    $reflection = new ReflectionClass(DiscoverEtoroTraders::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === DiscoverEtoroTraders::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values();

    expect($publicMethods->all())->toBe(['handle']);
});

it('handle() has the exact input and return type contract', function (): void {
    $reflection = new ReflectionClass(DiscoverEtoroTraders::class);
    $method = $reflection->getMethod('handle');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(DiscoverEtoroTradersRequest::class);

    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe(DiscoverEtoroTradersResult::class);
});

it('constructor dependency types are exactly EtoroClient, RankingsMapper, ImportRankingPage, all private and readonly', function (): void {
    $reflection = new ReflectionClass(DiscoverEtoroTraders::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(3);

    $expectedTypes = [EtoroClient::class, RankingsMapper::class, ImportRankingPage::class];

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

it('DiscoverEtoroTraders does not use the Laravel HTTP client, config/env, Storage/Log/DB/Queue, Filament, or Livewire directly', function (): void {
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

    $reflection = new ReflectionClass(DiscoverEtoroTraders::class);
    $code = checkpointEDiscoverTradersCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('DiscoverEtoroTraders calls only rankings(), never another EtoroClient endpoint method', function (): void {
    $reflection = new ReflectionClass(DiscoverEtoroTraders::class);
    $code = checkpointEDiscoverTradersCodeWithoutComments($reflection);

    $forbiddenCalls = [
        'authenticatedUser(',
        'userProfile(',
        'userPerformance(',
        'userLivePortfolio(',
        'accountPnl(',
    ];

    foreach ($forbiddenCalls as $needle) {
        expect($code)->not->toContain($needle);
    }

    expect($code)->toContain('->rankings(');
});

it('DiscoverEtoroTraders catches only the four documented typed exceptions plus a best-effort Throwable rethrow', function (): void {
    $reflection = new ReflectionClass(DiscoverEtoroTraders::class);
    $code = checkpointEDiscoverTradersCodeWithoutComments($reflection);

    foreach (['EtoroConfigurationException', 'EtoroRequestException', 'EtoroUnexpectedResponseException', 'EtoroMappingException'] as $needle) {
        expect($code)->toContain('catch ('.$needle);
    }

    expect($code)->toContain('catch (Throwable $exception)');
    expect($code)->toContain('throw $exception;');
});

it('App\\Application\\Imports does not depend on App\\Console or App\\Filament', function (): void {
    foreach ([DiscoverEtoroTraders::class, DiscoverEtoroTradersRequest::class, DiscoverEtoroTradersResult::class] as $class) {
        $code = checkpointEDiscoverTradersCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
        expect($code)->not->toContain('App\\Filament');
    }
});
