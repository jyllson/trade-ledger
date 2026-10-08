<?php

use Illuminate\Support\Facades\Http;

const ACCOUNT_DEMO_PNL_URL = 'https://public-api.etoro.com/api/v1/trading/info/demo/pnl';

/**
 * @return array<string, mixed>
 */
function accountPnlPayload(string $name = 'account-pnl.json'): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/'.$name)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Fakes the demo P&L endpoint plus the market-data endpoints the
 * best-effort instrument enrichment calls.
 *
 * @param  array<string, mixed>|null  $payload
 */
function fakeDemoAccountPnl(?array $payload = null, int $status = 200, array $headers = []): void
{
    Http::fake([
        ACCOUNT_DEMO_PNL_URL => Http::response($payload ?? accountPnlPayload(), $status, $headers),
        'https://public-api.etoro.com/api/v1/market-data/instruments*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/instrument-display-data.json')), true), 200),
        'https://public-api.etoro.com/api/v1/market-data/instrument-types' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/Etoro/instrument-types.json')), true), 200),
    ]);
}

function configureEtoroForAccountTests(): void
{
    config([
        'etoro.enabled' => true,
        'etoro.base_url' => 'https://public-api.etoro.com',
        'etoro.api_key' => 'test-api-key-value',
        'etoro.user_key' => 'test-user-key-value',
    ]);
}
