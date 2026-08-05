<?php

use App\Etoro\Data\LivePortfolio;
use App\Etoro\Data\PortfolioPosition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Milestone 2, Checkpoint B architectural guarantees: App\Etoro\Data and
 * App\Etoro\Mappers must be pure PHP (no Laravel HTTP facade, no network
 * I/O); the DTOs must stay exactly as scoped (final readonly, no raw
 * payload, no unmodeled fields); and App\Analytics must still not depend on
 * App\Etoro (Checkpoint A's guarantee must still hold after Checkpoint B).
 */
function discoverCheckpointBClassesUnder(string $relativeAppPath, string $namespacePrefix): Collection
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

function discoverCheckpointBEtoroMappingClasses(): Collection
{
    return discoverCheckpointBClassesUnder('Etoro/Data', 'App\\Etoro\\Data')
        ->merge(discoverCheckpointBClassesUnder('Etoro/Mappers', 'App\\Etoro\\Mappers'))
        ->values();
}

function discoverCheckpointBAnalyticsClasses(): Collection
{
    return discoverCheckpointBClassesUnder('Analytics', 'App\\Analytics');
}

it('finds the Etoro Data and Mappers classes to scan', function (): void {
    expect(discoverCheckpointBEtoroMappingClasses())->not->toBeEmpty();
});

it('App\\Etoro\\Data and App\\Etoro\\Mappers do not depend on the Laravel HTTP facade or perform network I/O', function (): void {
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

    foreach (discoverCheckpointBEtoroMappingClasses() as $class) {
        $source = File::get((new ReflectionClass($class))->getFileName());

        foreach ($forbiddenSubstrings as $needle) {
            expect($source)->not->toContain($needle);
        }
    }
});

it('App\\Etoro\\Data and App\\Etoro\\Mappers source files do not contain a NUL byte', function (): void {
    foreach (discoverCheckpointBEtoroMappingClasses() as $class) {
        $source = File::get((new ReflectionClass($class))->getFileName());

        expect($source)->not->toContain(chr(0));
    }
});

it('PortfolioPosition and LivePortfolio are final readonly classes', function (): void {
    foreach ([PortfolioPosition::class, LivePortfolio::class] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    }
});

it('DTOs do not carry a raw payload property', function (): void {
    $forbiddenPropertyNames = ['rawPayload', 'raw', 'payload'];

    foreach ([PortfolioPosition::class, LivePortfolio::class] as $class) {
        $propertyNames = collect((new ReflectionClass($class))->getProperties())
            ->map(fn (ReflectionProperty $property): string => $property->getName());

        expect($propertyNames->intersect($forbiddenPropertyNames)->values()->all())->toBe([]);
    }
});

it('PortfolioPosition does not carry netProfit, credit, or undocumented ranking-only properties', function (): void {
    $forbiddenPropertyNames = [
        'netProfit',
        'profitPercentage',
        'realizedCreditPct',
        'unrealizedCreditPct',
        'avgPosSize',
        'optimalCopyPosSize',
        'socialTradeId',
        'parentPositionId',
        'openRate',
    ];

    $propertyNames = collect((new ReflectionClass(PortfolioPosition::class))->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName());

    expect($propertyNames->intersect($forbiddenPropertyNames)->values()->all())->toBe([]);
});

it('LivePortfolio does not carry realizedCreditPct or unrealizedCreditPct properties', function (): void {
    $propertyNames = collect((new ReflectionClass(LivePortfolio::class))->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName());

    expect($propertyNames->intersect(['realizedCreditPct', 'unrealizedCreditPct'])->values()->all())->toBe([]);
});

it('LivePortfolio exposes a derived hasUnmodeledSocialTrades method, not a stored property', function (): void {
    $reflection = new ReflectionClass(LivePortfolio::class);

    expect($reflection->hasMethod('hasUnmodeledSocialTrades'))->toBeTrue();

    $propertyNames = collect($reflection->getProperties())
        ->map(fn (ReflectionProperty $property): string => $property->getName());

    expect($propertyNames->contains('hasUnmodeledSocialTrades'))->toBeFalse();
});

it('App\\Analytics still does not depend on App\\Etoro after Checkpoint B', function (): void {
    expect(discoverCheckpointBAnalyticsClasses())->not->toBeEmpty();

    foreach (discoverCheckpointBAnalyticsClasses() as $class) {
        $reflection = new ReflectionClass($class);

        expect(File::get($reflection->getFileName()))->not->toContain('App\\Etoro');
    }
});
