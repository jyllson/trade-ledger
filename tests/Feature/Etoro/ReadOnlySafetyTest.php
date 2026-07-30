<?php

use App\Etoro\EtoroWriteGuard;
use App\Etoro\Exceptions\EtoroWriteModeNotAllowedException;

it('disables etoro write mode by default', function () {
    expect(config('etoro.allow_write'))->toBeFalse();
});

it('exposes all documented configuration keys', function () {
    expect(config('etoro'))->toHaveKeys([
        'enabled',
        'base_url',
        'api_key',
        'user_key',
        'environment',
        'allow_write',
        'requests_per_minute',
        'timeout_seconds',
        'connect_timeout_seconds',
        'store_raw_responses',
        'raw_response_retention_days',
    ]);
});

it('documents every etoro environment variable in .env.example', function (string $variable) {
    expect(file_get_contents(base_path('.env.example')))->toContain($variable);
})->with([
    'ETORO_ENABLED=',
    'ETORO_BASE_URL=',
    'ETORO_API_KEY=',
    'ETORO_USER_KEY=',
    'ETORO_ENVIRONMENT=',
    'ETORO_ALLOW_WRITE=false',
    'ETORO_REQUESTS_PER_MINUTE=',
    'ETORO_TIMEOUT_SECONDS=',
    'ETORO_CONNECT_TIMEOUT_SECONDS=',
    'ETORO_STORE_RAW_RESPONSES=',
    'ETORO_RAW_RESPONSE_RETENTION_DAYS=',
]);

it('defines no bearer token configuration', function () {
    expect(config('etoro'))->not->toHaveKey('bearer_token')
        ->and(file_get_contents(base_path('.env.example')))->not->toContain('ETORO_BEARER_TOKEN');
});

it('never reports write mode as allowed, even when the flag is enabled', function () {
    config(['etoro.allow_write' => true]);

    expect(app(EtoroWriteGuard::class)->allowsWrite())->toBeFalse();
});

it('fails closed when ETORO_ALLOW_WRITE is switched on', function () {
    config(['etoro.allow_write' => true]);

    app(EtoroWriteGuard::class)->ensureReadOnly();
})->throws(EtoroWriteModeNotAllowedException::class);

it('passes the read-only assertion when write mode is disabled', function () {
    config(['etoro.allow_write' => false]);

    app(EtoroWriteGuard::class)->ensureReadOnly();

    expect(app(EtoroWriteGuard::class)->allowsWrite())->toBeFalse();
});
