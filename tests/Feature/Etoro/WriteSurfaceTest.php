<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * PROJECT.md §10 and §17: the eToro integration layer must not expose any
 * write or trading capability during the MVP, and no public generic request
 * API is allowed — a private read-only request() helper is fine. The scan is
 * intentionally scoped to the App\Etoro namespace; the rest of the
 * application may legitimately contain its own POST/PUT/DELETE operations.
 *
 * This scan only guards method naming. The actual read-only behavior (GET
 * requests only) is proven during Milestone 1 through Http::fake() tests
 * against the real client.
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
