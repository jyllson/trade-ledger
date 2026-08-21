<?php

use App\Application\Imports\ImportRankingPage;
use App\Application\Imports\ImportRankingPageFromFixture;
use App\Console\Commands\EtoroImportRankingPageCommand;
use App\Etoro\FixtureSources\RankingFixtureSource;
use App\Etoro\Mappers\RankingsMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint C architectural guarantees for the read-only, fixture-only
 * etoro:import-ranking-page CLI entry point: it must depend only on
 * ImportRankingPageFromFixture (never directly on RankingFixtureSource,
 * RankingsMapper, ImportRankingPage, or EtoroClient), must never touch the
 * network, config/env, or Laravel storage/DB/queue/UI layers itself, and
 * must not implement a --live option. Same helper conventions as
 * EtoroCopyCoverageCommandArchitectureTest, prefixed here to avoid name
 * collisions across the test suite.
 */
function checkpointCImportRankingPageCommandCodeWithoutComments(ReflectionClass $reflection): string
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

// --- Discovery -----------------------------------------------------------------

it('finds the EtoroImportRankingPageCommand class to scan', function (): void {
    expect(class_exists(EtoroImportRankingPageCommand::class))->toBeTrue();
});

// --- Class shape -----------------------------------------------------------------

it('EtoroImportRankingPageCommand is final and extends Command', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isSubclassOf(Command::class))->toBeTrue();
});

it('handle() has an int return type and no parameters', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $method = $reflection->getMethod('handle');

    expect($method->getParameters())->toHaveCount(0);
    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe('int');
});

it('constructor has exactly one promoted, private, readonly ImportRankingPageFromFixture business dependency', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(ImportRankingPageFromFixture::class);
    expect($parameters[0]->isPromoted())->toBeTrue();

    $property = $reflection->getProperty($parameters[0]->getName());

    expect($property->isPrivate())->toBeTrue();
    expect($property->isReadOnly())->toBeTrue();
});

it('constructor calls parent::__construct()', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    expect($code)->toContain('parent::__construct()');
});

it('signature is exactly etoro:import-ranking-page with the one documented argument and no options', function (): void {
    /** @var EtoroImportRankingPageCommand $command */
    $command = Artisan::all()['etoro:import-ranking-page'];
    $definition = $command->getDefinition();

    expect($definition->hasArgument('period'))->toBeTrue();
    expect($definition->getArguments())->toHaveCount(1);
    expect($definition->getOptions())->toBeEmpty();
});

it('does not implement a --live option', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    expect($code)->not->toContain('--live');
    expect($code)->not->toContain("'live'");
});

// --- Dependency direction: the command's own dependencies -----------------------

it('EtoroImportRankingPageCommand does not reference RankingFixtureSource, RankingsMapper, ImportRankingPage, or EtoroClient', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    foreach (['RankingFixtureSource', 'RankingsMapper', 'EtoroClient'] as $forbiddenName) {
        expect($code)->not->toContain($forbiddenName);
    }

    // The class's own name (EtoroImportRankingPageCommand) and the allowed
    // use case (ImportRankingPageFromFixture) both legitimately contain
    // "ImportRankingPage" as a substring, so the bare importer class is
    // checked via its exact usage patterns instead of a plain substring
    // match.
    expect($code)->not->toContain('use App\\Application\\Imports\\ImportRankingPage;');
    expect($code)->not->toContain('ImportRankingPage $');
    expect($code)->not->toContain('ImportRankingPage::class');
    expect($code)->not->toContain('new ImportRankingPage');
});

it('EtoroImportRankingPageCommand calls ImportRankingPageFromFixture::handle() exactly once', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    expect(substr_count($code, '->handle('))->toBe(1);
});

// --- Sanitization boundary: no lower-layer exception dependency (review finding) -

it('EtoroImportRankingPageCommand does not import or reference any App\\Etoro exception type, including RankingFixtureException and EtoroMappingException', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    $forbiddenExceptionReferences = [
        'RankingFixtureException',
        'RankingFixtureFailureReason',
        'EtoroMappingException',
        'EtoroMappingErrorReason',
        'App\\Etoro\\Exceptions',
        'App\\Etoro\\FixtureSources\\RankingFixture',
    ];

    foreach ($forbiddenExceptionReferences as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroImportRankingPageCommand has exactly one catch block and it catches Throwable broadly', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    expect(substr_count($code, 'catch ('))->toBe(1);
    expect($code)->toContain('catch (Throwable)');
});

it('EtoroImportRankingPageCommand never prints a caught exception\'s own getMessage()', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    expect($code)->not->toContain('getMessage()');
    expect($code)->toContain('Offline fixture ranking-page import failed.');
});

// --- Environment guard (review finding): cannot silently disappear ----------------

it('EtoroImportRankingPageCommand refuses local/testing outside app()->environment(), and the guard textually precedes the use case call', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    $guardNeedle = "app()->environment(['local', 'testing'])";
    $guardPosition = strpos($code, $guardNeedle);

    expect($guardPosition)->not->toBeFalse("Expected to find the exact guard expression: {$guardNeedle}");

    $useCasePosition = strpos($code, '$this->useCase->handle(');

    expect($useCasePosition)->not->toBeFalse();
    expect($guardPosition)->toBeLessThan($useCasePosition);

    // The guard must also textually precede the argument('period') read —
    // it must not be possible to reorder the guard after input parsing
    // without this test failing.
    $periodArgumentPosition = strpos($code, "argument('period')");

    expect($periodArgumentPosition)->not->toBeFalse();
    expect($guardPosition)->toBeLessThan($periodArgumentPosition);
});

it('the environment guard does not read config() or env() directly', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    expect($code)->not->toContain('config(');
    expect($code)->not->toContain('env(');
});

// --- Forbidden behaviors: network, config/env, storage/DB/queue, UI frameworks --

it('EtoroImportRankingPageCommand does not touch the network, config/env, Storage/DB/Queue, or Filament/Livewire', function (): void {
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

    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroImportRankingPageCommand does not reference tests/ paths', function (): void {
    $reflection = new ReflectionClass(EtoroImportRankingPageCommand::class);
    $code = checkpointCImportRankingPageCommandCodeWithoutComments($reflection);

    expect($code)->not->toContain('tests/Fixtures');
    expect($code)->not->toContain('base_path(\'tests');
});

// --- Reverse dependency direction: existing layers stay unaware -----------------

it('App\\Application\\Imports does not depend on App\\Console', function (): void {
    foreach ([ImportRankingPageFromFixture::class, ImportRankingPage::class] as $class) {
        $code = checkpointCImportRankingPageCommandCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

it('RankingFixtureSource and RankingsMapper do not depend on App\\Console', function (): void {
    foreach ([RankingFixtureSource::class, RankingsMapper::class] as $class) {
        $code = checkpointCImportRankingPageCommandCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

// --- NUL byte check ---------------------------------------------------------------

it('EtoroImportRankingPageCommand source file does not contain a NUL byte', function (): void {
    $source = File::get(app_path('Console/Commands/EtoroImportRankingPageCommand.php'));

    expect($source)->not->toContain(chr(0));
});
