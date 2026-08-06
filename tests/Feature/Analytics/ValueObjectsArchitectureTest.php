<?php

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Milestone 2 architectural guarantee: App\Analytics\ValueObjects must not
 * depend on App\Etoro (aside from Percentage::fromEtoroInvestmentPct()'s
 * *name* — its type signature carries no App\Etoro type). Etoro adapters in
 * a later checkpoint are allowed to depend on the Analytics layer; this test
 * only guards the forbidden Analytics → Etoro direction. It must also
 * represent every numeric value as an exact int (never float, so
 * equality/threshold comparisons stay exact), and must never perform
 * network I/O.
 */
function discoverAnalyticsValueObjectClasses(): Collection
{
    return collect(File::allFiles(app_path('Analytics/ValueObjects')))
        ->map(function (SplFileInfo $file): string {
            $relativePath = str($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\');

            return 'App\\Analytics\\ValueObjects\\'.$relativePath;
        })
        ->filter(fn (string $class): bool => class_exists($class));
}

it('finds the value object classes to scan', function (): void {
    expect(discoverAnalyticsValueObjectClasses())->not->toBeEmpty();
});

it('does not depend on App\\Etoro', function (): void {
    foreach (discoverAnalyticsValueObjectClasses() as $class) {
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

it('never represents internal state as float', function (): void {
    foreach (discoverAnalyticsValueObjectClasses() as $class) {
        $properties = (new ReflectionClass($class))->getProperties();

        expect($properties)->not->toBeEmpty();

        foreach ($properties as $property) {
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType) {
                expect($type->getName())->not->toBe('float', "Property {$class}::\${$property->getName()} must not be float.");
            }
        }
    }
});

it('never performs network I/O', function (): void {
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

    foreach (discoverAnalyticsValueObjectClasses() as $class) {
        $source = File::get((new ReflectionClass($class))->getFileName());

        foreach ($forbiddenSubstrings as $needle) {
            expect($source)->not->toContain($needle);
        }
    }
});

it('declares Money::$cents and Percentage::$partsPerBillion explicitly as int', function (): void {
    $moneyType = (new ReflectionProperty(Money::class, 'cents'))->getType();
    $percentageType = (new ReflectionProperty(Percentage::class, 'partsPerBillion'))->getType();

    expect($moneyType instanceof ReflectionNamedType && $moneyType->getName() === 'int')->toBeTrue();
    expect($percentageType instanceof ReflectionNamedType && $percentageType->getName() === 'int')->toBeTrue();
});

it('confirms Money and Percentage were both scanned', function (): void {
    // Intentionally not an exhaustive/exact-list assertion — a legitimate
    // future value object added to this directory should not fail this
    // test just for existing alongside Money and Percentage.
    $classes = discoverAnalyticsValueObjectClasses()->values()->all();

    expect($classes)->toContain(Money::class);
    expect($classes)->toContain(Percentage::class);
});
