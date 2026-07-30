<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * PROJECT.md §10 and §17: the eToro integration layer must not expose any
 * write or trading capability during the MVP. The scan is intentionally
 * scoped to the App\Etoro namespace — the rest of the application may
 * legitimately contain its own POST/PUT/DELETE operations.
 */
it('contains no write-capable methods in the eToro integration layer', function () {
    $forbiddenMethodNames = [
        'post',
        'put',
        'patch',
        'delete',
        'send',
        'request',
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
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class)
            ->map(fn (ReflectionMethod $method): string => strtolower($method->getName()));

        expect($methods->intersect($forbiddenMethodNames)->values()->all())
            ->toBe([], "Class {$class} exposes a forbidden write-capable method.");
    }
});
