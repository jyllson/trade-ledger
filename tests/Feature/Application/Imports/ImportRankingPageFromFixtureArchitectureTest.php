<?php

use App\Application\Imports\ImportRankingPage;
use App\Application\Imports\ImportRankingPageFromFixture;
use App\Application\Imports\ImportRankingPageFromFixtureResult;
use App\Etoro\Data\RankingEntry;
use App\Etoro\Data\RankingPage;
use App\Etoro\Data\RankingPagination;
use App\Etoro\FixtureSources\RankingFixtureException;
use App\Etoro\FixtureSources\RankingFixtureFailureReason;
use App\Etoro\FixtureSources\RankingFixtureSource;
use App\Etoro\Mappers\RankingsMapper;
use App\Etoro\RankingQuery;
use App\Models\ImportRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Checkpoint C architectural guarantees for the fixture-only ranking-page
 * import pipeline: ImportRankingPageFromFixture must depend only on
 * RankingFixtureSource, RankingsMapper, and ImportRankingPage — never on
 * EtoroClient, the Laravel HTTP client, config/env, or Storage/Log/DB/
 * Queue/Filament/Livewire — and App\Etoro\FixtureSources must itself have
 * no network path and no dependency on App\Application. Same helper
 * conventions as EtoroApplicationArchitectureTest, prefixed here to avoid
 * name collisions across the test suite.
 */
function checkpointCRankingFixtureArchitectureDiscoverClasses(string $relativeAppPath, string $namespacePrefix): Collection
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

function checkpointCRankingFixtureArchitectureCodeWithoutComments(ReflectionClass $reflection): string
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

// --- Discovery ---------------------------------------------------------------

it('finds the ImportRankingPageFromFixture classes to scan', function (): void {
    expect(class_exists(ImportRankingPageFromFixture::class))->toBeTrue();
    expect(class_exists(ImportRankingPageFromFixtureResult::class))->toBeTrue();
});

// --- Class shape ---------------------------------------------------------------

it('ImportRankingPageFromFixture is final and not readonly', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixture::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeFalse();
});

it('ImportRankingPageFromFixtureResult is final and readonly', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixtureResult::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

it('ImportRankingPageFromFixture exposes exactly one public behavior method: handle', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixture::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === ImportRankingPageFromFixture::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->reject(fn (string $name): bool => $name === '__construct')
        ->values();

    expect($publicMethods->all())->toBe(['handle']);
});

it('handle() has the exact input and return type contract', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixture::class);
    $method = $reflection->getMethod('handle');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getName())->toBe('rankingQuery');
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(RankingQuery::class);

    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe(ImportRankingPageFromFixtureResult::class);
});

it('constructor dependency types are exactly RankingFixtureSource, RankingsMapper, ImportRankingPage, all private and readonly', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixture::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(3);

    $expectedTypes = [RankingFixtureSource::class, RankingsMapper::class, ImportRankingPage::class];

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

it('ImportRankingPageFromFixtureResult has exactly three public readonly properties with the exact expected types', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixtureResult::class);

    $properties = collect($reflection->getProperties())->keyBy(fn (ReflectionProperty $property): string => $property->getName());

    expect($properties->keys()->sort()->values()->all())->toBe(['entryCount', 'fixturePagination', 'importRun']);

    foreach ($properties as $property) {
        expect($property->isPublic())->toBeTrue();
        expect($property->isReadOnly())->toBeTrue();
    }

    expect($properties['entryCount']->getType()->getName())->toBe('int');
    expect($properties['fixturePagination']->getType()->getName())->toBe(RankingPagination::class);
    expect($properties['importRun']->getType()->getName())->toBe(ImportRun::class);
});

it('ImportRankingPageFromFixtureResult does not carry a RankingPage or RankingEntry property', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixtureResult::class);

    $propertyTypeNames = collect($reflection->getProperties())
        ->map(function (ReflectionProperty $property): ?string {
            $type = $property->getType();

            return $type instanceof ReflectionNamedType ? $type->getName() : null;
        });

    expect($propertyTypeNames)->not->toContain(RankingPage::class);
    expect($propertyTypeNames)->not->toContain(RankingEntry::class);
});

// --- Dependency direction ----------------------------------------------------

it('ImportRankingPageFromFixture does not reference EtoroClient anywhere', function (): void {
    foreach ([ImportRankingPageFromFixture::class, ImportRankingPageFromFixtureResult::class] as $class) {
        $code = checkpointCRankingFixtureArchitectureCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('EtoroClient');
    }
});

it('App\\Etoro\\FixtureSources does not depend on App\\Application or App\\Console', function (): void {
    $classes = checkpointCRankingFixtureArchitectureDiscoverClasses('Etoro/FixtureSources', 'App\\Etoro\\FixtureSources');

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $code = checkpointCRankingFixtureArchitectureCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Application');
        expect($code)->not->toContain('App\\Console');
    }
});

// --- Forbidden behaviors -------------------------------------------------------

it('ImportRankingPageFromFixture and App\\Etoro\\FixtureSources never touch the network, config/env, Storage/Log/DB/Queue, Filament, or Livewire', function (): void {
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

    $classes = [
        ImportRankingPageFromFixture::class,
        ImportRankingPageFromFixtureResult::class,
        RankingFixtureSource::class,
        RankingFixtureException::class,
        RankingFixtureFailureReason::class,
    ];

    foreach ($classes as $class) {
        $code = checkpointCRankingFixtureArchitectureCodeWithoutComments(new ReflectionClass($class));

        foreach ($forbiddenSubstrings as $needle) {
            expect($code)->not->toContain($needle);
        }
    }
});

it('ImportRankingPageFromFixture does not catch Throwable or Exception itself', function (): void {
    $reflection = new ReflectionClass(ImportRankingPageFromFixture::class);
    $code = checkpointCRankingFixtureArchitectureCodeWithoutComments($reflection);

    expect($code)->not->toContain('catch (');
    expect($code)->not->toContain('catch(');
});

it('RankingFixtureException never includes the fixture path in its static factory bodies', function (): void {
    $reflection = new ReflectionClass(RankingFixtureException::class);
    $code = checkpointCRankingFixtureArchitectureCodeWithoutComments($reflection);

    expect($code)->not->toContain('$path');
    expect($code)->not->toContain('resource_path');
});

// --- NUL byte check ------------------------------------------------------------

it('Checkpoint C ranking-fixture source files do not contain a NUL byte', function (): void {
    $paths = [
        app_path('Etoro/FixtureSources/RankingFixtureSource.php'),
        app_path('Etoro/FixtureSources/RankingFixtureException.php'),
        app_path('Etoro/FixtureSources/RankingFixtureFailureReason.php'),
        app_path('Application/Imports/ImportRankingPageFromFixture.php'),
        app_path('Application/Imports/ImportRankingPageFromFixtureResult.php'),
        app_path('Console/Commands/EtoroImportRankingPageCommand.php'),
    ];

    foreach ($paths as $path) {
        $source = File::get($path);

        expect($source)->not->toContain(chr(0));
    }
});
