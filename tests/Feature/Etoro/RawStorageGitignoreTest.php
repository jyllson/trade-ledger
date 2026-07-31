<?php

use Illuminate\Support\Facades\Process;

/**
 * Authoritative proof (via git itself, not a hand-rolled pattern match)
 * that raw eToro response files can never be committed. Relies on the
 * blanket `*` / `!.gitignore` rule already present in
 * storage/app/private/.gitignore — see DECISIONS.md for why a nested
 * storage/app/private/etoro/.gitignore would not work (git does not
 * descend into an already-excluded directory to apply further rules).
 */
it('git-ignores every file under storage/app/private/etoro/raw', function () {
    $result = Process::path(base_path())->run([
        'git', 'check-ignore', '--quiet', 'storage/app/private/etoro/raw/example.json',
    ]);

    expect($result->exitCode())->toBe(0);
});

it('git-ignores the etoro directory itself, so raw files never reach the index', function () {
    $result = Process::path(base_path())->run([
        'git', 'check-ignore', '--quiet', 'storage/app/private/etoro',
    ]);

    expect($result->exitCode())->toBe(0);
});
