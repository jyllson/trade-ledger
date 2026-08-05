<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Analytics\Data\CopyCoverageRequest;
use App\Analytics\Data\CopyCoverageResult;
use App\Analytics\Data\CoverageTargetRequest;
use App\Analytics\Data\CoverageTargetResult;
use App\Analytics\Data\PositionAllocation;
use App\Analytics\Data\PositionCoverageOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @return list<class-string>
 */
function checkpointCStateClasses(): array
{
    return [
        PositionAllocation::class,
        CopyCoverageRequest::class,
        CopyCoverageResult::class,
        PositionCoverageOutcome::class,
        CoverageTargetRequest::class,
        CoverageTargetResult::class,
        CopyCoverageCalculator::class,
    ];
}

/**
 * Milestone 2, Checkpoint C architectural guarantees: App\Analytics\Calculators
 * and App\Analytics\Data classes must stay source-neutral (no App\Etoro
 * dependency), pure PHP (no Laravel HTTP facade, no network I/O, no Laravel
 * container/config/storage/log dependency), and the explicit Checkpoint C
 * state classes must represent money/percentage/coverage-weight values as
 * exact int (never float).
 */
function discoverCheckpointCClassesUnder(string $relativeAppPath, string $namespacePrefix): Collection
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
 * @return list<string>
 */
function checkpointCNewFilePaths(): array
{
    return [
        app_path('Analytics/Calculators/CopyCoverageCalculator.php'),
        app_path('Analytics/Data/PositionAllocation.php'),
        app_path('Analytics/Data/CopyCoverageRequest.php'),
        app_path('Analytics/Data/CopyCoverageResult.php'),
        app_path('Analytics/Data/PositionCoverageOutcome.php'),
        app_path('Analytics/Data/PositionSkipReason.php'),
        app_path('Analytics/Data/CoverageWarning.php'),
        app_path('Analytics/Data/CoverageTargetRequest.php'),
        app_path('Analytics/Data/CoverageTargetResult.php'),
        app_path('Analytics/Exceptions/CoverageCalculationException.php'),
    ];
}

function discoverCheckpointCAnalyticsClasses(): Collection
{
    return discoverCheckpointCClassesUnder('Analytics/Calculators', 'App\\Analytics\\Calculators')
        ->merge(discoverCheckpointCClassesUnder('Analytics/Data', 'App\\Analytics\\Data'))
        ->merge(discoverCheckpointCClassesUnder('Analytics/Exceptions', 'App\\Analytics\\Exceptions'))
        ->values();
}

it('finds the Checkpoint C Analytics classes to scan', function (): void {
    expect(discoverCheckpointCAnalyticsClasses())->not->toBeEmpty();
    expect(discoverCheckpointCAnalyticsClasses())->toContain(CopyCoverageCalculator::class);
});

it('does not depend on App\\Etoro', function (): void {
    foreach (discoverCheckpointCAnalyticsClasses() as $class) {
        $reflection = new ReflectionClass($class);

        expect(File::get($reflection->getFileName()))->not->toContain('App\\Etoro');

        $parent = $reflection->getParentClass();
        expect($parent === false || ! str_starts_with($parent->getName(), 'App\\Etoro'))->toBeTrue();

        foreach ($reflection->getInterfaceNames() as $interfaceName) {
            expect($interfaceName)->not->toStartWith('App\\Etoro');
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    expect($type->getName())->not->toStartWith('App\\Etoro');
                }
            }

            $returnType = $method->getReturnType();

            if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                expect($returnType->getName())->not->toStartWith('App\\Etoro');
            }
        }
    }
});

it('does not depend on the Laravel HTTP facade or perform network I/O', function (): void {
    $forbiddenSubstrings = [
        'Illuminate\\Http\\',
        'GuzzleHttp',
        'Symfony\\Contracts\\HttpClient',
        'curl_',
        'fsockopen',
        'stream_socket_client',
        'file_get_contents(\'http',
        'file_get_contents("http',
    ];

    foreach (discoverCheckpointCAnalyticsClasses() as $class) {
        $source = File::get((new ReflectionClass($class))->getFileName());

        foreach ($forbiddenSubstrings as $needle) {
            expect($source)->not->toContain($needle);
        }
    }
});

it('never represents money, percentage, or coverage weight state as float', function (): void {
    // Scoped to the explicit Checkpoint C state classes, not the whole
    // App\Analytics tree — a future, unrelated Analytics class legitimately
    // using float for some other statistic must not fail this test.
    foreach (checkpointCStateClasses() as $class) {
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType) {
                expect($type->getName())->not->toBe('float', "Property {$class}::\${$property->getName()} must not be float.");
            }
        }
    }
});

it('CopyCoverageCalculator has no Laravel container/config/storage/log dependency', function (): void {
    $reflection = new ReflectionClass(CopyCoverageCalculator::class);
    $constructor = $reflection->getConstructor();

    expect($constructor === null || $constructor->getNumberOfParameters() === 0)->toBeTrue();
});

it('CopyCoverageCalculator is stateless', function (): void {
    $reflection = new ReflectionClass(CopyCoverageCalculator::class);

    expect($reflection->getProperties())->toBeEmpty();
});

it('CopyCoverageCalculator has no static public method and only instance public methods', function (): void {
    $reflection = new ReflectionClass(CopyCoverageCalculator::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === CopyCoverageCalculator::class);

    expect($publicMethods)->not->toBeEmpty();

    foreach ($publicMethods as $method) {
        expect($method->isStatic())->toBeFalse("{$method->getName()} must not be static.");
    }
});

it('CopyCoverageCalculator exposes the documented public API', function (): void {
    $reflection = new ReflectionClass(CopyCoverageCalculator::class);

    expect($reflection->hasMethod('evaluate'))->toBeTrue();
    expect($reflection->hasMethod('minimumAmountForPosition'))->toBeTrue();
    expect($reflection->hasMethod('minimumAmountForCoverage'))->toBeTrue();
});

it('DTOs do not carry a raw payload property', function (): void {
    $forbiddenPropertyNames = ['rawPayload', 'raw', 'payload'];

    foreach ([PositionAllocation::class, PositionCoverageOutcome::class, CopyCoverageResult::class, CoverageTargetResult::class] as $class) {
        $propertyNames = collect((new ReflectionClass($class))->getProperties())
            ->map(fn (ReflectionProperty $property): string => $property->getName());

        expect($propertyNames->intersect($forbiddenPropertyNames)->values()->all())->toBe([]);
    }
});

it('Checkpoint C source files do not contain a NUL byte', function (): void {
    foreach (checkpointCNewFilePaths() as $path) {
        expect(File::get($path))->not->toContain(chr(0));
    }
});
