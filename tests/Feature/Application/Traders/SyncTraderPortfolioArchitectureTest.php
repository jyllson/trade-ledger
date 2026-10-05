<?php

/**
 * Source-level guards for the portfolio sync path (D-038): each use case
 * calls only its own read-only EtoroClient methods, and the job/command
 * never talk to the transport or mappers directly.
 */
function portfolioSyncSource(string $relativePath): string
{
    return file_get_contents(base_path($relativePath));
}

it('calls only the expected read-only EtoroClient methods', function (string $path, array $methods) {
    preg_match_all('/etoroClient->(\w+)\(/', portfolioSyncSource($path), $matches);

    expect(array_values(array_unique($matches[1])))->toBe($methods);
})->with([
    'portfolio' => ['app/Application/Traders/SyncTraderPortfolio.php', ['userLivePortfolio']],
    'instrument metadata' => ['app/Application/Traders/EnrichInstrumentMetadata.php', ['instrumentDisplayData', 'instrumentTypes']],
]);

it('keeps the job and command away from the transport, mappers, and raw HTTP', function (string $path) {
    expect(portfolioSyncSource($path))->not->toContain('EtoroClient')
        ->not->toContain('LivePortfolioMapper')
        ->not->toContain('InstrumentMetadataMapper')
        ->not->toContain('Facades\\Http')
        ->not->toContain('->post(')
        ->not->toContain('->delete(');
})->with([
    'app/Jobs/SyncTraderPortfolioJob.php',
    'app/Console/Commands/EtoroSyncPortfolioCommand.php',
]);

it('never logs or prints exception messages in the use cases', function (string $path) {
    expect(portfolioSyncSource($path))
        ->not->toContain('getMessage()')
        ->not->toContain('Log::');
})->with([
    'app/Application/Traders/SyncTraderPortfolio.php',
    'app/Application/Traders/EnrichInstrumentMetadata.php',
]);
