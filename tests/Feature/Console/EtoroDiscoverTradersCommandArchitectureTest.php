<?php

use App\Application\Imports\DiscoverEtoroTraders;
use App\Application\Imports\ImportRankingPage;
use App\Console\Commands\EtoroDiscoverTradersCommand;
use App\Etoro\EtoroClient;
use App\Etoro\Mappers\RankingsMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Checkpoint E architectural guarantees for the read-only, live
 * etoro:discover-traders CLI entry point: it must depend only on
 * DiscoverEtoroTraders (never directly on EtoroClient, RankingsMapper, or
 * ImportRankingPage), must never touch the network, config/env, or Laravel
 * storage/DB/queue/UI layers itself, and must not reference any App\Etoro
 * exception type directly.
 */
function checkpointEDiscoverTradersCommandCodeWithoutComments(ReflectionClass $reflection): string
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

it('finds the EtoroDiscoverTradersCommand class to scan', function (): void {
    expect(class_exists(EtoroDiscoverTradersCommand::class))->toBeTrue();
});

it('EtoroDiscoverTradersCommand is final and extends Command', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isSubclassOf(Command::class))->toBeTrue();
});

it('handle() has an int return type and no parameters', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $method = $reflection->getMethod('handle');

    expect($method->getParameters())->toHaveCount(0);
    expect($method->getReturnType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($method->getReturnType()->getName())->toBe('int');
});

it('constructor has exactly one promoted, private, readonly DiscoverEtoroTraders business dependency', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(1);
    expect($parameters[0]->getType())->toBeInstanceOf(ReflectionNamedType::class);
    expect($parameters[0]->getType()->getName())->toBe(DiscoverEtoroTraders::class);
    expect($parameters[0]->isPromoted())->toBeTrue();
});

it('constructor calls parent::__construct()', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    expect($code)->toContain('parent::__construct()');
});

it('signature is exactly etoro:discover-traders with one argument and the documented options', function (): void {
    /** @var EtoroDiscoverTradersCommand $command */
    $command = Artisan::all()['etoro:discover-traders'];
    $definition = $command->getDefinition();

    expect($definition->hasArgument('period'))->toBeTrue();
    expect($definition->getArguments())->toHaveCount(1);

    foreach (['max-pages', 'start-page', 'sort', 'country'] as $option) {
        expect($definition->hasOption($option))->toBeTrue();
    }
    expect($definition->getOptions())->toHaveCount(4);
});

it('EtoroDiscoverTradersCommand does not reference EtoroClient, RankingsMapper, or ImportRankingPage', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    foreach (['EtoroClient', 'RankingsMapper', 'ImportRankingPage'] as $forbiddenName) {
        expect($code)->not->toContain($forbiddenName);
    }
});

it('EtoroDiscoverTradersCommand does not import or reference any App\\Etoro exception type', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    foreach (['EtoroConfigurationException', 'EtoroRequestException', 'EtoroUnexpectedResponseException', 'EtoroMappingException', 'App\\Etoro\\Exceptions'] as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroDiscoverTradersCommand has exactly two catch blocks: local InvalidArgumentException input validation, and a broad Throwable catch-all for the use case call', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    expect(substr_count($code, 'catch ('))->toBe(2);
    expect($code)->toContain('catch (InvalidArgumentException)');
    expect($code)->toContain('catch (Throwable)');
});

it('EtoroDiscoverTradersCommand calls DiscoverEtoroTraders::handle() exactly once', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    expect(substr_count($code, '->handle('))->toBe(1);
});

it('EtoroDiscoverTradersCommand does not touch the network, config/env, Storage/DB/Queue, or Filament/Livewire', function (): void {
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
        'config(',
        'env(',
        'Storage::',
        'DB::',
        'Queue::',
        'dispatch(',
        'Filament',
        'Livewire',
    ];

    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroDiscoverTradersCommand does not use float/double casts or float conversion functions', function (): void {
    $forbiddenSubstrings = ['(float)', '(double)', 'floatval(', 'doubleval('];

    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    foreach ($forbiddenSubstrings as $needle) {
        expect($code)->not->toContain($needle);
    }
});

it('EtoroDiscoverTradersCommand never calls getMessage() on any caught exception, including the local InvalidArgumentException', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    expect($code)->not->toContain('getMessage()');
});

it('does not implement a --live option', function (): void {
    $reflection = new ReflectionClass(EtoroDiscoverTradersCommand::class);
    $code = checkpointEDiscoverTradersCommandCodeWithoutComments($reflection);

    expect($code)->not->toContain('--live');
});

it('App\\Application\\Imports discovery classes do not depend on App\\Console', function (): void {
    foreach ([DiscoverEtoroTraders::class] as $class) {
        $code = checkpointEDiscoverTradersCommandCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

it('EtoroClient, RankingsMapper, and ImportRankingPage do not depend on App\\Console', function (): void {
    foreach ([EtoroClient::class, RankingsMapper::class, ImportRankingPage::class] as $class) {
        $code = checkpointEDiscoverTradersCommandCodeWithoutComments(new ReflectionClass($class));

        expect($code)->not->toContain('App\\Console');
    }
});

it('EtoroDiscoverTradersCommand source file does not contain a NUL byte', function (): void {
    $source = File::get(app_path('Console/Commands/EtoroDiscoverTradersCommand.php'));

    expect($source)->not->toContain(chr(0));
});
