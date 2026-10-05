<?php

/**
 * Source-level guards for the performance sync path (D-034): the use case
 * calls exactly one EtoroClient method, and the job/command never talk to
 * the transport or mapper directly.
 */
function performanceSyncSource(string $relativePath): string
{
    return file_get_contents(base_path($relativePath));
}

it('calls only EtoroClient::userGainHistory from the use case', function () {
    $source = performanceSyncSource('app/Application/Traders/SyncTraderPerformance.php');

    preg_match_all('/etoroClient->(\w+)\(/', $source, $matches);

    expect(array_unique($matches[1]))->toBe(['userGainHistory']);
});

it('keeps the job and command away from the transport, mapper, and raw HTTP', function (string $path) {
    $source = performanceSyncSource($path);

    expect($source)->not->toContain('EtoroClient')
        ->not->toContain('GainHistoryMapper')
        ->not->toContain('Facades\\Http')
        ->not->toContain('->post(')
        ->not->toContain('->delete(');
})->with([
    'app/Jobs/SyncTraderPerformanceJob.php',
    'app/Console/Commands/EtoroSyncPerformanceCommand.php',
]);

it('never logs or prints exception messages in the use case', function () {
    expect(performanceSyncSource('app/Application/Traders/SyncTraderPerformance.php'))
        ->not->toContain('getMessage()')
        ->not->toContain('Log::');
});
