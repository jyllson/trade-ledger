<?php

use App\Analytics\Calculators\CopyCoverageCalculator;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage;
use App\Console\Commands\EtoroCopyTargetCommand;
use App\Etoro\Adapters\LivePortfolioCoverageAdapter;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\LivePortfolioMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Architectural guarantees for the read-only etoro:copy-target CLI entry
 * point: it must depend only on FindTraderMinimumCopyAmountForCoverage
 * (never directly on EtoroClient, the mapper, adapter, or calculator), must
 * never touch the network, config/env, or Laravel storage/DB/queue/UI layers
 * itself, must never do float arithmetic or catch a generic
 * Throwable/Exception, and must build its Percentage input only through the
 * exact parts-per-billion path. Helper names are prefixed
 * "etoroCopyTargetArchitecture" (not the "applicationCheckpointB..." prefix
 * used by EtoroCopyCoverageCommandArchitectureTest.php) to avoid a global
 * Pest function name collision between the two architecture test files.
 */
function etoroCopyTargetArchitectureCodeWithoutComments(ReflectionClass $reflection): string
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

it('finds the EtoroCopyTargetCommand class to scan', function (): void {
    expect(class_exists(EtoroCopyTargetCommand::class))->toBeTrue();
});

// --- Class shape ---------------------------------------------------------------

it('EtoroCopyTargetCommand is final and extends Command', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isSubclassOf(Command::class))->toBeTrue();
});

it('handle() has an int return type and no parameters', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $method = $reflection->getMethod('handle');

    expect($method->getParameters())->toHaveCount(0);
    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe('int');
});

it('constructor has exactly one promoted, private, readonly FindTraderMinimumCopyAmountForCoverage business dependency', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(FindTraderMinimumCopyAmountForCoverage::class);
    expect($parameters[0]->isPromoted())->toBeTrue();

    $property = $reflection->getProperty($parameters[0]->getName());

    expect($property->isPrivate())->toBeTrue();
    expect($property->isReadOnly())->toBeTrue();
    expect($property->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($property->getType()->getName())->toBe(FindTraderMinimumCopyAmountForCoverage::class);

    $promotedBusinessProperties = collect($reflection->getProperties())
        ->filter(fn (ReflectionProperty $candidate): bool => $candidate->getDeclaringClass()->getName() === EtoroCopyTargetCommand::class
            && $candidate->getType() instanceof ReflectionNamedType
            && ! $candidate->getType()->isBuiltin())
        ->values();

    expect($promotedBusinessProperties)->toHaveCount(1);
});

it('constructor calls parent::__construct()', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    expect($code)->toContain('parent::__construct()');
});

it('signature is exactly etoro:copy-target with the four documented arguments and no options', function (): void {
    /** @var EtoroCopyTargetCommand $command */
    $command = Artisan::all()['etoro:copy-target'];
    $definition = $command->getDefinition();

    expect($definition->hasArgument('trader-username'))->toBeTrue();
    expect($definition->hasArgument('target-coverage-percent'))->toBeTrue();
    expect($definition->hasArgument('minimum-position-cents'))->toBeTrue();
    expect($definition->hasArgument('platform-minimum-copy-cents'))->toBeTrue();
    expect($definition->getArguments())->toHaveCount(4);
    expect($definition->getOptions())->toBeEmpty();
});

// --- Dependency direction: the command's own dependencies ---------------------

it('EtoroCopyTargetCommand does not reference EtoroClient, LivePortfolioMapper, LivePortfolioCoverageAdapter, or CopyCoverageCalculator', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    foreach (['EtoroClient', 'LivePortfolioMapper', 'LivePortfolioCoverageAdapter', 'CopyCoverageCalculator'] as $forbiddenName) {
        expect($code)->not->toContain($forbiddenName);
    }
});

it('EtoroCopyTargetCommand calls FindTraderMinimumCopyAmountForCoverage::handle() exactly once', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    expect(substr_count($code, '->handle('))->toBe(1);
});

it('App\\Application\\Etoro, App\\Etoro, and App\\Analytics remain unaware of EtoroCopyTargetCommand', function (): void {
    foreach ([FindTraderMinimumCopyAmountForCoverage::class, EtoroClient::class, LivePortfolioMapper::class, LivePortfolioCoverageAdapter::class, CopyCoverageCalculator::class] as $class) {
        $code = etoroCopyTargetArchitectureCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('EtoroCopyTargetCommand');
        expect($code)->not->toContain('App\\Console');
    }
});

// --- Forbidden behaviors: network, config/env, storage/DB/queue, UI frameworks -

it('EtoroCopyTargetCommand does not touch the network, config/env, Storage/DB/Queue, or Filament/Livewire', function (): void {
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
        'Log::',
        'DB::',
        'Queue::',
        'dispatch(',
        'Filament',
        'Livewire',
    ];

    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroCopyTargetCommand does not use float/double casts or float conversion functions', function (): void {
    $forbiddenSubstrings = [
        '(float)',
        '(double)',
        'floatval(',
        'doubleval(',
    ];

    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroCopyTargetCommand does not catch a generic Throwable or Exception', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    expect($code)->not->toContain('catch (Throwable');
    expect($code)->not->toContain('catch (\\Throwable');
    expect($code)->not->toContain('catch (Exception ');
    expect($code)->not->toContain('catch (\\Exception ');
});

it('EtoroCopyTargetCommand does not expose a raw/rawPayload/credential/private presentation surface', function (): void {
    $forbiddenSubstrings = [
        'rawPayload',
        'getTrace',
        'getTraceAsString',
        'api_key',
        'user_key',
        'apiKey',
        'userKey',
    ];

    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroCopyTargetCommand builds its Percentage input only through the exact parts-per-billion factory', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    expect($code)->toContain('Percentage::fromPartsPerBillion(');
    expect($code)->not->toContain('Percentage::fromEtoroInvestmentPct(');
});

it('EtoroCopyTargetCommand does not reference another eToro endpoint method', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    $forbiddenCalls = [
        'authenticatedUser(',
        'rankings(',
        'userProfile(',
        'userPerformance(',
        'userLivePortfolio(',
        'accountPnl(',
    ];

    foreach ($forbiddenCalls as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroCopyTargetCommand does not implement additional options beyond the four documented arguments', function (): void {
    $reflection = new ReflectionClass(EtoroCopyTargetCommand::class);
    $code = etoroCopyTargetArchitectureCodeWithoutComments($reflection);

    expect($code)->not->toContain('--');
});

// --- NUL byte check ------------------------------------------------------------

it('EtoroCopyTargetCommand source files do not contain a NUL byte', function (): void {
    foreach ([
        app_path('Console/Commands/EtoroCopyTargetCommand.php'),
        base_path('tests/Feature/Console/EtoroCopyTargetCommandTest.php'),
        base_path('tests/Feature/Console/EtoroCopyTargetCommandArchitectureTest.php'),
    ] as $path) {
        $source = File::get($path);

        expect($source)->not->toContain(chr(0));
    }
});
