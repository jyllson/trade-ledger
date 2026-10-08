<?php

use App\Etoro\EtoroClient;
use App\Etoro\EtoroDemoCopyClient;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * PROJECT.md §2, §10 and §17: the eToro integration layer must not expose
 * any write or trading capability, and no public generic request API is
 * allowed — a private request helper is fine. The scan is intentionally
 * scoped to the App\Etoro namespace; the rest of the application may
 * legitimately contain its own POST/PUT/DELETE operations.
 *
 * The single exception (D-050) is EtoroDemoCopyClient: four typed demo
 * copy-trading methods, every request checked by EtoroWriteGuard. The
 * forbidden method names below apply to it as well. The guard's route
 * allow-list is proven in DemoCopyWriteGuardTest, the actual requests in
 * EtoroDemoCopyClientTest (Http::fake()).
 */
it('contains no write-capable methods in the eToro integration layer', function () {
    $forbiddenAtAnyVisibility = [
        'post',
        'put',
        'patch',
        'delete',
        'executeorder',
        'placeorder',
        'editorder',
        'cancelorder',
        'openposition',
        'closeposition',
        'startcopying',
        'stopcopying',
        'startcopy',
        'stopcopy',
        'deposit',
        'withdraw',
        'transfer',
    ];

    $forbiddenWhenPublic = [
        'request',
        'send',
    ];

    $classes = collect(File::allFiles(app_path('Etoro')))
        ->map(function (SplFileInfo $file): string {
            $relativePath = str($file->getRelativePathname())
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\');

            return 'App\\Etoro\\'.$relativePath;
        })
        ->filter(fn (string $class): bool => class_exists($class));

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $methods = collect((new ReflectionClass($class))->getMethods())
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class);

        $allMethodNames = $methods
            ->map(fn (ReflectionMethod $method): string => strtolower($method->getName()));

        expect($allMethodNames->intersect($forbiddenAtAnyVisibility)->values()->all())
            ->toBe([], "Class {$class} exposes a forbidden write-capable method.");

        $publicMethodNames = $methods
            ->filter(fn (ReflectionMethod $method): bool => $method->isPublic())
            ->map(fn (ReflectionMethod $method): string => strtolower($method->getName()));

        expect($publicMethodNames->intersect($forbiddenWhenPublic)->values()->all())
            ->toBe([], "Class {$class} exposes a public generic request API; only typed read methods may be public.");
    }
});

/**
 * @return array<string, string>
 */
function etoroLayerSources(): array
{
    return collect(File::allFiles(app_path('Etoro')))
        ->mapWithKeys(fn (SplFileInfo $file): array => [$file->getRelativePathname() => $file->getContents()])
        ->all();
}

it('sends non-GET HTTP requests only from EtoroDemoCopyClient', function () {
    foreach (etoroLayerSources() as $relativePath => $source) {
        $sendsWrites = preg_match('/->(post|put|patch|delete|send)\(/i', $source) === 1;

        expect($sendsWrites)->toBe(
            $relativePath === 'EtoroDemoCopyClient.php',
            "{$relativePath} must not send non-GET eToro requests.",
        );
    }
});

it('keeps EtoroClient GET-only with no demo copy or write route', function () {
    $source = File::get((new ReflectionClass(EtoroClient::class))->getFileName());

    expect($source)->not->toContain('/trading/copy')
        ->not->toContain('/trading/execution')
        ->not->toContain('EtoroWriteGuard');
});

it('exposes exactly the four typed demo copy methods publicly, each guarded', function () {
    $publicMethods = collect((new ReflectionClass(EtoroDemoCopyClient::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === EtoroDemoCopyClient::class)
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->sort()
        ->values()
        ->all();

    expect($publicMethods)->toBe(['__construct', 'close', 'pollOutcome', 'preCheck', 'startOrAdjust']);

    $source = File::get((new ReflectionClass(EtoroDemoCopyClient::class))->getFileName());

    expect($source)->toContain('$url = EtoroWriteGuard::DEMO_COPY_ORIGIN.$path;')
        ->toContain('$this->guard->ensureDemoCopyRequestAllowed($method, $url);')
        ->toContain("withOptions(['allow_redirects' => false])")
        ->not->toContain("config('etoro.base_url')")
        ->not->toContain('/real');
});
