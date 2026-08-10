<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Etoro\EvaluateTraderCopyCoverage;
use App\Application\Etoro\EvaluateTraderCopyCoverageResult;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverageResult;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\Data\LivePortfolio;
use App\Etoro\EtoroApiResponse;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\LivePortfolioMapper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Architectural guarantees for every use case under App\Application\Etoro:
 * the namespace must depend only on App\Etoro and App\Analytics (never on
 * Laravel's HTTP client, config/env, Storage/Log/DB/Queue, Filament, or
 * Livewire), and the existing App\Etoro / App\Analytics layers must remain
 * unaware of it. Originally written for the first use case
 * (EvaluateTraderCopyCoverage, "Checkpoint A" of the
 * feature/etoro-application-orchestration stream); extended here for the
 * second use case (FindTraderMinimumCopyAmountForCoverage, "Checkpoint A" of
 * the feature/etoro-target-coverage stream). Helper names below are
 * therefore scoped to "EtoroApplicationArchitecture", not to either
 * checkpoint label, since a second like-named checkpoint would otherwise be
 * ambiguous.
 */
function etoroApplicationArchitectureDiscoverClasses(string $relativeAppPath, string $namespacePrefix): Collection
{
    return collect(File::allFiles(app_path($relativeAppPath)))
        ->map(function (SplFileInfo $file) use ($namespacePrefix): string {
            $relativePathname = str($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\');

            return $namespacePrefix.'\\'.$relativePathname;
        })
        ->filter(fn (string $class): bool => class_exists($class) || interface_exists($class) || enum_exists($class));
}

/**
 * The class file's source with T_COMMENT and T_DOC_COMMENT tokens stripped —
 * every other token (imports, type hints, fully-qualified references, method
 * bodies, string literals, ...) is kept verbatim.
 */
function etoroApplicationArchitectureCodeWithoutComments(ReflectionClass $reflection): string
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

/**
 * @return list<string>
 */
function etoroApplicationArchitectureAllowedNamespacePrefixes(): array
{
    return ['App\\Application\\Etoro', 'App\\Etoro', 'App\\Analytics'];
}

function etoroApplicationArchitectureProductionFiles(): Collection
{
    return etoroApplicationArchitectureDiscoverClasses('Application/Etoro', 'App\\Application\\Etoro');
}

/**
 * @return list<string>
 */
function etoroApplicationArchitectureGuardedFilePaths(): array
{
    return [
        app_path('Application/Etoro/EvaluateTraderCopyCoverage.php'),
        app_path('Application/Etoro/EvaluateTraderCopyCoverageResult.php'),
        app_path('Application/Etoro/FindTraderMinimumCopyAmountForCoverage.php'),
        app_path('Application/Etoro/FindTraderMinimumCopyAmountForCoverageResult.php'),
        base_path('tests/Feature/Application/Etoro/EvaluateTraderCopyCoverageTest.php'),
        base_path('tests/Feature/Application/Etoro/FindTraderMinimumCopyAmountForCoverageTest.php'),
        base_path('tests/Feature/Application/EtoroApplicationArchitectureTest.php'),
    ];
}

// --- Discovery -------------------------------------------------------------

it('finds the Application Checkpoint A classes to scan', function (): void {
    expect(etoroApplicationArchitectureProductionFiles())->not->toBeEmpty();
    expect(etoroApplicationArchitectureProductionFiles())->toContain(EvaluateTraderCopyCoverage::class);
    expect(etoroApplicationArchitectureProductionFiles())->toContain(EvaluateTraderCopyCoverageResult::class);
});

it('finds the FindTraderMinimumCopyAmountForCoverage classes to scan', function (): void {
    expect(etoroApplicationArchitectureProductionFiles())->toContain(FindTraderMinimumCopyAmountForCoverage::class);
    expect(etoroApplicationArchitectureProductionFiles())->toContain(FindTraderMinimumCopyAmountForCoverageResult::class);
});

// --- Class shape: EvaluateTraderCopyCoverage --------------------------------

it('EvaluateTraderCopyCoverage is final', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverage::class);

    expect($reflection->isFinal())->toBeTrue();
});

it('EvaluateTraderCopyCoverage is not a readonly class', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverage::class);

    expect($reflection->isReadOnly())->toBeFalse();
});

it('EvaluateTraderCopyCoverageResult is final and readonly', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverageResult::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('EvaluateTraderCopyCoverage exposes exactly one public behavior method: handle', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverage::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === EvaluateTraderCopyCoverage::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values();

    expect($publicMethods->all())->toBe(['handle']);
});

it('handle() has the exact input and return type contract', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverage::class);
    $method = $reflection->getMethod('handle');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(3);

    expect($parameters[0]->getName())->toBe('traderUsername');
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe('string');

    expect($parameters[1]->getName())->toBe('copyAmount');
    expect($parameters[1]->getType()->getName())->toBe(Money::class);

    expect($parameters[2]->getName())->toBe('minimumPositionAmount');
    expect($parameters[2]->getType()->getName())->toBe(Money::class);

    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe(EvaluateTraderCopyCoverageResult::class);
});

it('constructor dependency types are exactly EtoroClient, LivePortfolioMapper, LivePortfolioCoverageAdapter, CopyCoverageCalculator, all private and readonly', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverage::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(4);

    $expectedTypes = [
        EtoroClient::class,
        LivePortfolioMapper::class,
        LivePortfolioCoverageAdapter::class,
        CopyCoverageCalculator::class,
    ];

    foreach ($parameters as $index => $parameter) {
        expect($parameter->getType())->toBeInstanceOf(ReflectionNamedType::class);
        expect($parameter->getType()->getName())->toBe($expectedTypes[$index]);
    }

    $properties = $reflection->getProperties();

    expect($properties)->toHaveCount(4);

    foreach ($properties as $property) {
        expect($property->isPrivate())->toBeTrue();
        expect($property->isReadOnly())->toBeTrue();
    }
});

it('EvaluateTraderCopyCoverageResult does not carry a LivePortfolio or EtoroApiResponse property', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverageResult::class);

    $propertyTypeNames = collect($reflection->getProperties())
        ->map(function (ReflectionProperty $property): ?string {
            $type = $property->getType();

            return $type instanceof ReflectionNamedType ? $type->getName() : null;
        });

    expect($propertyTypeNames)->not->toContain(LivePortfolio::class);
    expect($propertyTypeNames)->not->toContain(EtoroApiResponse::class);
});

it('EvaluateTraderCopyCoverageResult has no Laravel framework dependency', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverageResult::class);
    $code = etoroApplicationArchitectureCodeWithoutComments($reflection);

    expect($code)->not->toContain('Illuminate\\');
});

// --- Class shape: FindTraderMinimumCopyAmountForCoverage --------------------

it('FindTraderMinimumCopyAmountForCoverage is final', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);

    expect($reflection->isFinal())->toBeTrue();
});

it('FindTraderMinimumCopyAmountForCoverage is not a readonly class', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);

    expect($reflection->isReadOnly())->toBeFalse();
});

it('FindTraderMinimumCopyAmountForCoverageResult is final and readonly', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverageResult::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('FindTraderMinimumCopyAmountForCoverage exposes exactly one public behavior method: handle', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === FindTraderMinimumCopyAmountForCoverage::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values();

    expect($publicMethods->all())->toBe(['handle']);
});

it('FindTraderMinimumCopyAmountForCoverage handle() has the exact input and return type contract', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);
    $method = $reflection->getMethod('handle');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(4);

    expect($parameters[0]->getName())->toBe('traderUsername');
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe('string');

    expect($parameters[1]->getName())->toBe('targetCoverage');
    expect($parameters[1]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[1]->getType()->getName())->toBe(Percentage::class);

    expect($parameters[2]->getName())->toBe('minimumPositionAmount');
    expect($parameters[2]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[2]->getType()->getName())->toBe(Money::class);

    expect($parameters[3]->getName())->toBe('platformMinimumCopyAmount');
    expect($parameters[3]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[3]->getType()->getName())->toBe(Money::class);

    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe(FindTraderMinimumCopyAmountForCoverageResult::class);
});

it('FindTraderMinimumCopyAmountForCoverage constructor dependency types are exactly EtoroClient, LivePortfolioMapper, LivePortfolioCoverageAdapter, CopyCoverageCalculator, all private and readonly, with no additional dependency', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(4);

    $expectedTypes = [
        EtoroClient::class,
        LivePortfolioMapper::class,
        LivePortfolioCoverageAdapter::class,
        CopyCoverageCalculator::class,
    ];

    foreach ($parameters as $index => $parameter) {
        expect($parameter->getType())->toBeInstanceOf(ReflectionNamedType::class);
        expect($parameter->getType()->getName())->toBe($expectedTypes[$index]);
        expect($parameter->isPromoted())->toBeTrue();
    }

    $properties = $reflection->getProperties();

    expect($properties)->toHaveCount(4);

    foreach ($properties as $property) {
        expect($property->isPrivate())->toBeTrue();
        expect($property->isReadOnly())->toBeTrue();
    }
});

it('FindTraderMinimumCopyAmountForCoverageResult has exactly three public readonly properties with the exact expected types', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverageResult::class);

    $properties = collect($reflection->getProperties())->keyBy(fn (ReflectionProperty $property): string => $property->getName());

    expect($properties->keys()->sort()->values()->all())->toBe(['coverageTarget', 'requestId', 'traderUsername']);

    foreach ($properties as $property) {
        expect($property->isPublic())->toBeTrue();
        expect($property->isReadOnly())->toBeTrue();
    }

    expect($properties['traderUsername']->getType()->getName())->toBe('string');
    expect($properties['requestId']->getType()->getName())->toBe('string');
    expect($properties['coverageTarget']->getType()->getName())->toBe(CoverageTargetResult::class);
});

it('FindTraderMinimumCopyAmountForCoverageResult does not carry a LivePortfolio or EtoroApiResponse property', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverageResult::class);

    $propertyTypeNames = collect($reflection->getProperties())
        ->map(function (ReflectionProperty $property): ?string {
            $type = $property->getType();

            return $type instanceof ReflectionNamedType ? $type->getName() : null;
        });

    expect($propertyTypeNames)->not->toContain(LivePortfolio::class);
    expect($propertyTypeNames)->not->toContain(EtoroApiResponse::class);
});

it('FindTraderMinimumCopyAmountForCoverageResult has no Laravel framework dependency', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverageResult::class);
    $code = etoroApplicationArchitectureCodeWithoutComments($reflection);

    expect($code)->not->toContain('Illuminate\\');
});

// --- Dependency direction (applies to every class under App\Application\Etoro)

it('App\\Application\\Etoro depends only on App\\Etoro and App\\Analytics', function (): void {
    $allowedPrefixes = etoroApplicationArchitectureAllowedNamespacePrefixes();

    foreach (etoroApplicationArchitectureProductionFiles() as $class) {
        $code = etoroApplicationArchitectureCodeWithoutComments(new ReflectionClass($class));

        preg_match_all('/App\\\\[A-Za-z0-9_\\\\]+/', $code, $matches);

        foreach (array_unique($matches[0]) as $reference) {
            $isAllowed = collect($allowedPrefixes)->contains(fn (string $prefix): bool => str_starts_with($reference, $prefix));

            expect($isAllowed)->toBeTrue("Unexpected App\\ dependency in {$class}: {$reference}");
        }
    }
});

it('App\\Application\\Etoro does not depend on App\\Console', function (): void {
    foreach (etoroApplicationArchitectureProductionFiles() as $class) {
        $code = etoroApplicationArchitectureCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

it('App\\Etoro does not depend on App\\Application', function (): void {
    $classes = etoroApplicationArchitectureDiscoverClasses('Etoro', 'App\\Etoro');

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $code = etoroApplicationArchitectureCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Application');
    }
});

it('App\\Analytics does not depend on App\\Application', function (): void {
    $classes = etoroApplicationArchitectureDiscoverClasses('Analytics', 'App\\Analytics');

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $code = etoroApplicationArchitectureCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Application');
    }
});

it('LivePortfolioMapper, LivePortfolioCoverageAdapter and CopyCoverageCalculator do not depend on the Application layer', function (): void {
    foreach ([LivePortfolioMapper::class, LivePortfolioCoverageAdapter::class, CopyCoverageCalculator::class] as $class) {
        $code = etoroApplicationArchitectureCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Application');
    }
});

// --- Forbidden behaviors: EvaluateTraderCopyCoverage ------------------------

it('EvaluateTraderCopyCoverage does not use the Laravel HTTP client, config/env, Storage/Log/DB/Queue, Filament, Livewire, or raw sockets', function (): void {
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
        'file_get_contents(\'http',
        'file_get_contents("http',
        'GuzzleHttp',
        'Symfony\\Contracts\\HttpClient',
        'Symfony\\Component\\HttpClient',
        'Psr\\Http\\Client',
        'socket_create',
        'socket_connect',
    ];

    $reflection = new ReflectionClass(EvaluateTraderCopyCoverage::class);
    $code = etoroApplicationArchitectureCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EvaluateTraderCopyCoverage calls only userLivePortfolio(), never another EtoroClient endpoint method', function (): void {
    $reflection = new ReflectionClass(EvaluateTraderCopyCoverage::class);
    $code = etoroApplicationArchitectureCodeWithoutComments($reflection);

    $forbiddenCalls = [
        'authenticatedUser(',
        'rankings(',
        'userProfile(',
        'userPerformance(',
        'accountPnl(',
    ];

    foreach ($forbiddenCalls as $needle) {
        expect($code)->not->toContain($needle);
    }

    expect($code)->toContain('userLivePortfolio(');
});

// --- Forbidden behaviors: FindTraderMinimumCopyAmountForCoverage ------------

it('FindTraderMinimumCopyAmountForCoverage does not use the Laravel HTTP client, config/env, Storage/Log/DB/Queue, Filament, Livewire, or raw sockets', function (): void {
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
        'file_get_contents(\'http',
        'file_get_contents("http',
        'GuzzleHttp',
        'Symfony\\Contracts\\HttpClient',
        'Symfony\\Component\\HttpClient',
        'Psr\\Http\\Client',
        'socket_create',
        'socket_connect',
    ];

    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);
    $code = etoroApplicationArchitectureCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('FindTraderMinimumCopyAmountForCoverage calls only userLivePortfolio(), never another EtoroClient endpoint method', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);
    $code = etoroApplicationArchitectureCodeWithoutComments($reflection);

    $forbiddenCalls = [
        'authenticatedUser(',
        'rankings(',
        'userProfile(',
        'userPerformance(',
        'accountPnl(',
    ];

    foreach ($forbiddenCalls as $needle) {
        expect($code)->not->toContain($needle);
    }

    expect($code)->toContain('userLivePortfolio(');
});

it('FindTraderMinimumCopyAmountForCoverage and its Result do not use float/double casts or float conversion functions', function (): void {
    $forbiddenSubstrings = [
        '(float)',
        '(double)',
        'floatval(',
        'doubleval(',
    ];

    foreach ([FindTraderMinimumCopyAmountForCoverage::class, FindTraderMinimumCopyAmountForCoverageResult::class] as $class) {
        $code = etoroApplicationArchitectureCodeWithoutComments(new ReflectionClass($class));

        foreach ($forbiddenSubstrings as $needle) {
            expect($code)->not->toContain($needle);
        }
    }
});

it('FindTraderMinimumCopyAmountForCoverage does not catch Throwable or Exception itself', function (): void {
    $reflection = new ReflectionClass(FindTraderMinimumCopyAmountForCoverage::class);
    $code = etoroApplicationArchitectureCodeWithoutComments($reflection);

    expect($code)->not->toContain('catch (');
    expect($code)->not->toContain('catch(');
});

// --- NUL byte check ----------------------------------------------------------

it('EtoroApplicationArchitectureTest-guarded source files do not contain a NUL byte', function (): void {
    foreach (etoroApplicationArchitectureGuardedFilePaths() as $path) {
        $source = File::get($path);

        expect($source)->not->toContain(chr(0));
    }
});
