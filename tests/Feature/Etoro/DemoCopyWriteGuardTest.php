<?php

use App\Etoro\EtoroWriteGuard;
use App\Etoro\Exceptions\EtoroDemoCopyNotAllowedException;

/**
 * D-050: the only eToro write exception is the exact demo copy allow-list,
 * behind ETORO_ALLOW_DEMO_COPY. ETORO_ALLOW_WRITE opens nothing.
 */
function enableEveryWriteFlag(): void
{
    config(['etoro.allow_write' => true, 'etoro.allow_demo_copy' => true]);
}

it('refuses every real-account route even with both flags enabled', function (string $method, string $path) {
    enableEveryWriteFlag();

    expect(fn () => app(EtoroWriteGuard::class)->ensureDemoCopyRequestAllowed($method, EtoroWriteGuard::DEMO_COPY_ORIGIN.$path))
        ->toThrow(EtoroDemoCopyNotAllowedException::class, 'real-account');
})->with([
    ['POST', '/api/v2/trading/copy/real'],
    ['POST', '/api/v2/trading/copy/real/eligibility'],
    ['POST', '/api/v2/trading/copy/real/close'],
    ['DELETE', '/api/v2/trading/copy/real'],
    ['GET', '/api/v2/trading/copy/real/tl-ref'],
    ['POST', '/api/v1/trading/execution/real/market-open-orders/by-amount'],
    ['POST', '/api/v2/trading/copy/demo/../real'],
    ['POST', '/API/V2/TRADING/COPY/REAL'],
    ['GET', '/api/v2/trading/copy/demo/real-1'],
]);

it('refuses every write route that is not on the demo copy allow-list, even with both flags enabled', function (string $method, string $path) {
    enableEveryWriteFlag();

    expect(fn () => app(EtoroWriteGuard::class)->ensureDemoCopyRequestAllowed($method, EtoroWriteGuard::DEMO_COPY_ORIGIN.$path))
        ->toThrow(EtoroDemoCopyNotAllowedException::class, 'allow-list');
})->with([
    'documented DELETE close (not used)' => ['DELETE', '/api/v2/trading/copy/demo'],
    'PUT on copy' => ['PUT', '/api/v2/trading/copy/demo'],
    'PATCH on copy' => ['PATCH', '/api/v2/trading/copy/demo'],
    'POST to a reference' => ['POST', '/api/v2/trading/copy/demo/tl-ref'],
    'demo order' => ['POST', '/api/v1/trading/execution/demo/market-open-orders/by-amount'],
    'demo close position' => ['POST', '/api/v1/trading/execution/demo/market-close-orders/positions/1'],
    'transfer' => ['POST', '/api/v1/money/transfer'],
    'watchlist' => ['POST', '/api/v1/watchlists'],
    'feed post' => ['POST', '/api/v1/feeds/post'],
    'trailing slash' => ['POST', '/api/v2/trading/copy/demo/'],
    'query string' => ['POST', '/api/v2/trading/copy/demo?mirrorID=1'],
    'fragment' => ['POST', '/api/v2/trading/copy/demo#x'],
    'double slash' => ['POST', '/api/v2/trading/copy//demo'],
    'dot segment in write' => ['POST', '/api/v2/trading/copy/demo/./close'],
    'encoded slash in write' => ['POST', '/api/v2/trading/copy/demo%2Fclose'],
    'v1 copy' => ['POST', '/api/v1/trading/copy/demo'],
    'GET eligibility' => ['GET', '/api/v2/trading/copy/demo/eligibility'],
    'GET close' => ['GET', '/api/v2/trading/copy/demo/close'],
    'GET encoded slash' => ['GET', '/api/v2/trading/copy/demo/a%2Fb'],
    'GET too long reference' => ['GET', '/api/v2/trading/copy/demo/'.str_repeat('a', 36)],
    'GET nested path' => ['GET', '/api/v2/trading/copy/demo/a/b'],
    'GET dot segment' => ['GET', '/api/v2/trading/copy/demo/..'],
]);

it('refuses allow-listed demo copy routes while ETORO_ALLOW_DEMO_COPY is off — ETORO_ALLOW_WRITE does not open them', function (string $method, string $path) {
    config(['etoro.allow_write' => true, 'etoro.allow_demo_copy' => false]);

    expect(fn () => app(EtoroWriteGuard::class)->ensureDemoCopyRequestAllowed($method, EtoroWriteGuard::DEMO_COPY_ORIGIN.$path))
        ->toThrow(EtoroDemoCopyNotAllowedException::class, 'disabled');
})->with([
    ['POST', '/api/v2/trading/copy/demo'],
    ['POST', '/api/v2/trading/copy/demo/eligibility'],
    ['POST', '/api/v2/trading/copy/demo/close'],
    ['GET', '/api/v2/trading/copy/demo/tl-01abc'],
]);

it('allows exactly the documented demo copy routes when ETORO_ALLOW_DEMO_COPY is true', function (string $method, string $path) {
    config(['etoro.allow_write' => false, 'etoro.allow_demo_copy' => true]);

    app(EtoroWriteGuard::class)->ensureDemoCopyRequestAllowed($method, EtoroWriteGuard::DEMO_COPY_ORIGIN.$path);

    expect(true)->toBeTrue();
})->with([
    ['POST', '/api/v2/trading/copy/demo'],
    ['post', '/api/v2/trading/copy/demo/eligibility'],
    ['POST', '/api/v2/trading/copy/demo/close'],
    ['GET', '/api/v2/trading/copy/demo/tl-01abc_DEF'],
]);

it('treats only a strict boolean true as enabled', function (mixed $value) {
    config(['etoro.allow_demo_copy' => $value]);

    expect(app(EtoroWriteGuard::class)->allowsDemoCopy())->toBeFalse();
})->with([false, null, 'true', '1', 1, 'yes']);

it('ships demo copy disabled by default in config source and .env.example', function () {
    // Asserted against the sources, not config(): the owner's local .env
    // may enable demo copy (like ETORO_STORE_RAW_RESPONSES in ReadOnlySafetyTest).
    expect(file_get_contents(config_path('etoro.php')))->toContain("env('ETORO_ALLOW_DEMO_COPY', false)")
        ->and(file_get_contents(base_path('.env.example')))->toContain('ETORO_ALLOW_DEMO_COPY=false');
});

it('never reports generic write mode as allowed, even with demo copy enabled', function () {
    enableEveryWriteFlag();

    expect(app(EtoroWriteGuard::class)->allowsWrite())->toBeFalse();
});

it('checks the final URL: refuses any target that is not exactly the pinned https origin', function (string $url) {
    enableEveryWriteFlag();

    expect(fn () => app(EtoroWriteGuard::class)->ensureDemoCopyRequestAllowed('POST', $url))
        ->toThrow(EtoroDemoCopyNotAllowedException::class, 'not the pinned eToro API origin');
})->with([
    'http' => ['http://public-api.etoro.com/api/v2/trading/copy/demo'],
    'other host' => ['https://evil.example/api/v2/trading/copy/demo'],
    'lookalike host' => ['https://public-api.etoro.com.evil.example/api/v2/trading/copy/demo'],
    'userinfo' => ['https://user@public-api.etoro.com/api/v2/trading/copy/demo'],
    'host as userinfo' => ['https://public-api.etoro.com@evil.example/api/v2/trading/copy/demo'],
    'port' => ['https://public-api.etoro.com:8443/api/v2/trading/copy/demo'],
    'explicit 443' => ['https://public-api.etoro.com:443/api/v2/trading/copy/demo'],
    'uppercase scheme' => ['HTTPS://public-api.etoro.com/api/v2/trading/copy/demo'],
    'backslash' => ['https://public-api.etoro.com\\api/v2/trading/copy/demo'],
    'whitespace' => ['https://public-api.etoro.com/api/v2/trading/copy/demo '],
    'relative' => ['/api/v2/trading/copy/demo'],
    'garbage' => ['::not a url'],
]);

it('refuses a final URL whose query or encoded path points at the real account', function (string $url, string $message) {
    enableEveryWriteFlag();

    expect(fn () => app(EtoroWriteGuard::class)->ensureDemoCopyRequestAllowed('POST', $url))
        ->toThrow(EtoroDemoCopyNotAllowedException::class, $message);
})->with([
    'real path, demo in query' => ['https://public-api.etoro.com/api/v2/trading/copy/real?x=/api/v2/trading/copy/demo', 'real-account'],
    'demo path, real in query' => ['https://public-api.etoro.com/api/v2/trading/copy/demo?x=/real', 'real-account'],
    'encoded traversal' => ['https://public-api.etoro.com/api/v2/trading/copy/demo%2F..%2Freal', 'allow-list'],
    'dot-dot traversal' => ['https://public-api.etoro.com/api/v2/trading/copy/demo/../demo', 'allow-list'],
]);
