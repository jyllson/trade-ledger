<?php

use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Milestone 2, Checkpoint E architectural guarantees: App\Etoro\Adapters
 * must stay pure PHP (no Laravel HTTP facade, no Laravel facade/config/
 * storage/log usage, no network I/O), must not depend on EtoroClient or on
 * CopyCoverageCalculator (it builds request DTOs only — it does not call
 * the calculator), is allowed to depend on App\Analytics DTO/value-object
 * classes, and App\Analytics must still not depend on App\Etoro (the
 * Checkpoint A/B/C guarantee must still hold after Checkpoint E).
 */
function checkpointEDiscoverAdapterClasses(): Collection
{
    return collect(File::allFiles(app_path('Etoro/Adapters')))
        ->map(function (SplFileInfo $file): string {
            $relativePathname = str($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\');

            return 'App\\Etoro\\Adapters\\'.$relativePathname;
        })
        ->filter(fn (string $class): bool => class_exists($class) || interface_exists($class) || enum_exists($class));
}

/**
 * The class file's source with T_COMMENT and T_DOC_COMMENT tokens
 * stripped — every other token (use imports, type hints, fully-qualified
 * `new`/`::class` references, method bodies, string literals, ...) is
 * kept verbatim. This lets a class document *why* it does not depend on
 * something (mentioning the class name in prose) without tripping a
 * dependency check, while still catching every real reference: an import,
 * a type hint, a fully-qualified instantiation, or a class-string literal.
 */
function checkpointECodeWithoutComments(ReflectionClass $reflection): string
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

function checkpointEDiscoverAnalyticsClasses(): Collection
{
    return collect(File::allFiles(app_path('Analytics')))
        ->map(function (SplFileInfo $file): string {
            $relativePathname = str($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\');

            return 'App\\Analytics\\'.$relativePathname;
        })
        ->filter(fn (string $class): bool => class_exists($class) || interface_exists($class) || enum_exists($class));
}

it('finds the Checkpoint E Adapter classes to scan', function (): void {
    expect(checkpointEDiscoverAdapterClasses())->not->toBeEmpty();
    expect(checkpointEDiscoverAdapterClasses())->toContain(LivePortfolioCoverageAdapter::class);
});

it('LivePortfolioCoverageAdapter is final', function (): void {
    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);

    expect($reflection->isFinal())->toBeTrue();
});

it('LivePortfolioCoverageAdapter is not readonly (it is not a DTO)', function (): void {
    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);

    expect($reflection->isReadOnly())->toBeFalse();
});

it('LivePortfolioCoverageAdapter has no constructor and no dependency state', function (): void {
    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);
    $constructor = $reflection->getConstructor();

    expect($constructor === null || $constructor->getNumberOfParameters() === 0)->toBeTrue();
    expect($reflection->getProperties())->toBeEmpty();
});

it('App\\Etoro\\Adapters does not depend on the Laravel HTTP facade, Laravel facades, config/storage/log, or network I/O', function (): void {
    $forbiddenSubstrings = [
        'Illuminate\\',
        'GuzzleHttp',
        'Symfony\\Contracts\\HttpClient',
        'Symfony\\Component\\HttpClient',
        'Psr\\Http\\Client',
        'curl_',
        'fsockopen',
        'stream_socket_client',
        'socket_create',
        'socket_connect',
        'file_get_contents(\'http',
        'file_get_contents("http',
        'config(',
        'Storage::',
        'Log::',
    ];

    foreach (checkpointEDiscoverAdapterClasses() as $class) {
        $code = checkpointECodeWithoutComments(new ReflectionClass($class));

        foreach ($forbiddenSubstrings as $needle) {
            expect($code)->not->toContain($needle);
        }
    }
});

it('LivePortfolioCoverageAdapter does not depend on EtoroClient', function (): void {
    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);
    $code = checkpointECodeWithoutComments($reflection);

    expect($code)->not->toContain('App\\Etoro\\EtoroClient');
    expect($code)->not->toContain('EtoroClient');

    foreach ($reflection->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                expect($type->getName())->not->toBe('App\\Etoro\\EtoroClient');
            }
        }

        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
            expect($returnType->getName())->not->toBe('App\\Etoro\\EtoroClient');
        }
    }
});

it('LivePortfolioCoverageAdapter does not depend on CopyCoverageCalculator', function (): void {
    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);
    $code = checkpointECodeWithoutComments($reflection);

    expect($code)->not->toContain('App\\Analytics\\Calculators\\CopyCoverageCalculator');
    expect($code)->not->toContain('CopyCoverageCalculator');

    foreach ($reflection->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                expect($type->getName())->not->toBe('App\\Analytics\\Calculators\\CopyCoverageCalculator');
            }
        }

        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
            expect($returnType->getName())->not->toBe('App\\Analytics\\Calculators\\CopyCoverageCalculator');
        }
    }
});

it('LivePortfolioCoverageAdapter is allowed to depend on App\\Analytics DTO/value-object classes', function (): void {
    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);
    $source = File::get($reflection->getFileName());

    expect($source)->toContain('App\\Analytics\\Data\\PositionAllocation');
    expect($source)->toContain('App\\Analytics\\ValueObjects\\Money');
    expect($source)->toContain('App\\Analytics\\ValueObjects\\Percentage');
});

it('App\\Analytics still does not depend on App\\Etoro after Checkpoint E', function (): void {
    expect(checkpointEDiscoverAnalyticsClasses())->not->toBeEmpty();

    foreach (checkpointEDiscoverAnalyticsClasses() as $class) {
        $reflection = new ReflectionClass($class);

        expect(File::get($reflection->getFileName()))->not->toContain('App\\Etoro');
    }
});

it('Adapter source files do not contain a NUL byte', function (): void {
    foreach (checkpointEDiscoverAdapterClasses() as $class) {
        $source = File::get((new ReflectionClass($class))->getFileName());

        expect($source)->not->toContain(chr(0));
    }
});

it('LivePortfolioCoverageAdapter exposes exactly two public methods: toCopyCoverageRequest and toCoverageTargetRequest', function (): void {
    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LivePortfolioCoverageAdapter::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->values();

    expect($publicMethods)->toHaveCount(2);
    expect($publicMethods->sort()->values()->all())->toBe(['toCopyCoverageRequest', 'toCoverageTargetRequest']);
});

it('LivePortfolioCoverageAdapter does not carry a raw/rawPayload/extra property', function (): void {
    $forbiddenPropertyNames = ['rawPayload', 'raw', 'payload', 'extra'];

    $reflection = new ReflectionClass(LivePortfolioCoverageAdapter::class);
    $propertyNames = collect($reflection->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName());

    expect($propertyNames->intersect($forbiddenPropertyNames)->values()->all())->toBe([]);
});
