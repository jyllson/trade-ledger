<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Application\Etoro\EvaluateTraderCopyCoverage;
use App\Application\Etoro\EvaluateTraderCopyCoverageResult;
use App\Console\Commands\EtoroCopyCoverageCommand;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\LivePortfolioMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Application Checkpoint B architectural guarantees for the read-only
 * etoro:copy-coverage CLI entry point: it must depend only on
 * EvaluateTraderCopyCoverage (never directly on EtoroClient, the mapper,
 * adapter, or calculator), must never touch the network, config/env, or
 * Laravel storage/DB/queue/UI layers itself, and the existing App\Etoro,
 * App\Analytics, and App\Application\Etoro layers must remain unaware of it.
 */
function applicationCheckpointBDiscoverClasses(string $relativeAppPath, string $namespacePrefix): Collection
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
function applicationCheckpointBCodeWithoutComments(ReflectionClass $reflection): string
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
function applicationCheckpointBNewFilePaths(): array
{
    return [
        app_path('Console/Commands/EtoroCopyCoverageCommand.php'),
        base_path('tests/Feature/Console/EtoroCopyCoverageCommandTest.php'),
        base_path('tests/Feature/Console/EtoroCopyCoverageCommandArchitectureTest.php'),
    ];
}

// --- Discovery ---------------------------------------------------------------

it('finds the EtoroCopyCoverageCommand class to scan', function (): void {
    expect(class_exists(EtoroCopyCoverageCommand::class))->toBeTrue();
});

// --- Class shape ---------------------------------------------------------------

it('EtoroCopyCoverageCommand is final and extends Command', function (): void {
    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isSubclassOf(Command::class))->toBeTrue();
});

it('handle() has an int return type and no parameters', function (): void {
    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $method = $reflection->getMethod('handle');

    expect($method->getParameters())->toHaveCount(0);
    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe('int');
});

it('constructor has exactly one promoted, private, readonly EvaluateTraderCopyCoverage business dependency', function (): void {
    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(EvaluateTraderCopyCoverage::class);
    expect($parameters[0]->isPromoted())->toBeTrue();

    $property = $reflection->getProperty($parameters[0]->getName());

    expect($property->isPrivate())->toBeTrue();
    expect($property->isReadOnly())->toBeTrue();
    expect($property->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($property->getType()->getName())->toBe(EvaluateTraderCopyCoverage::class);

    $promotedBusinessProperties = collect($reflection->getProperties())
        ->filter(fn (ReflectionProperty $candidate): bool => $candidate->getDeclaringClass()->getName() === EtoroCopyCoverageCommand::class
            && $candidate->getType() instanceof ReflectionNamedType
            && ! $candidate->getType()->isBuiltin())
        ->values();

    expect($promotedBusinessProperties)->toHaveCount(1);
});

it('constructor calls parent::__construct()', function (): void {
    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $code = applicationCheckpointBCodeWithoutComments($reflection);

    expect($code)->toContain('parent::__construct()');
});

it('signature is exactly etoro:copy-coverage with the three documented arguments and no options', function (): void {
    /** @var EtoroCopyCoverageCommand $command */
    $command = Artisan::all()['etoro:copy-coverage'];
    $definition = $command->getDefinition();

    expect($definition->hasArgument('trader-username'))->toBeTrue();
    expect($definition->hasArgument('copy-amount-cents'))->toBeTrue();
    expect($definition->hasArgument('minimum-position-cents'))->toBeTrue();
    expect($definition->getArguments())->toHaveCount(3);
    expect($definition->getOptions())->toBeEmpty();
});

// --- Dependency direction: the command's own dependencies ---------------------

it('EtoroCopyCoverageCommand does not reference EtoroClient, LivePortfolioMapper, LivePortfolioCoverageAdapter, or CopyCoverageCalculator', function (): void {
    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $code = applicationCheckpointBCodeWithoutComments($reflection);

    foreach (['EtoroClient', 'LivePortfolioMapper', 'LivePortfolioCoverageAdapter', 'CopyCoverageCalculator'] as $forbiddenName) {
        expect($code)->not->toContain($forbiddenName);
    }
});

it('EtoroCopyCoverageCommand calls EvaluateTraderCopyCoverage::handle() exactly once', function (): void {
    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $code = applicationCheckpointBCodeWithoutComments($reflection);

    expect(substr_count($code, '->handle('))->toBe(1);
});

// --- Forbidden behaviors: network, config/env, storage/DB/queue, UI frameworks -

it('EtoroCopyCoverageCommand does not touch the network, config/env, Storage/DB/Queue, or Filament/Livewire', function (): void {
    $forbiddenSubstrings = [
        'Illuminate\\Support\\Facades\\Http',
        'Illuminate\\Http\\Client',
        'Http::',
        'GuzzleHttp',
        'Symfony\\Contracts\\HttpClient',
        'Symfony\\Component\\HttpClient',
        'Psr\\Http\\Client',
        'curl_',
        'fsockopen',
        'stream_socket_client',
        'socket_create',
        'socket_connect',
        "file_get_contents('http",
        'file_get_contents("http',
        'config(',
        'env(',
        'Storage::',
        'DB::',
        'Queue::',
        'dispatch(',
        'Filament',
        'Livewire',
    ];

    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $code = applicationCheckpointBCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroCopyCoverageCommand does not use float/double casts or float conversion functions', function (): void {
    $forbiddenSubstrings = [
        '(float)',
        '(double)',
        'floatval(',
        'doubleval(',
    ];

    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $code = applicationCheckpointBCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroCopyCoverageCommand does not implement a --details option', function (): void {
    $reflection = new ReflectionClass(EtoroCopyCoverageCommand::class);
    $code = applicationCheckpointBCodeWithoutComments($reflection);

    expect($code)->not->toContain('details');
});

// --- Reverse dependency direction: existing layers stay unaware ---------------

it('App\\Application\\Etoro does not depend on App\\Console', function (): void {
    foreach ([EvaluateTraderCopyCoverage::class, EvaluateTraderCopyCoverageResult::class] as $class) {
        $code = applicationCheckpointBCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

it('App\\Etoro does not depend on App\\Console', function (): void {
    $classes = applicationCheckpointBDiscoverClasses('Etoro', 'App\\Etoro');

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $code = applicationCheckpointBCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

it('App\\Analytics does not depend on App\\Console', function (): void {
    $classes = applicationCheckpointBDiscoverClasses('Analytics', 'App\\Analytics');

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $code = applicationCheckpointBCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

it('EtoroClient, LivePortfolioMapper, LivePortfolioCoverageAdapter, and CopyCoverageCalculator do not depend on App\\Console', function (): void {
    foreach ([EtoroClient::class, LivePortfolioMapper::class, LivePortfolioCoverageAdapter::class, CopyCoverageCalculator::class] as $class) {
        $code = applicationCheckpointBCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

// --- NUL byte check ------------------------------------------------------------

it('Application Checkpoint B source files do not contain a NUL byte', function (): void {
    foreach (applicationCheckpointBNewFilePaths() as $path) {
        $source = File::get($path);

        expect($source)->not->toContain(chr(0));
    }
});
