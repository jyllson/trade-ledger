<?php

return [

    /*
    |--------------------------------------------------------------------------
    | eToro Public API
    |--------------------------------------------------------------------------
    |
    | Read-only integration with the eToro Public API. Credentials live only
    | in the local .env file or a secret manager and must never be logged,
    | committed, or exposed in exception context.
    |
    */

    'enabled' => env('ETORO_ENABLED', true),

    'base_url' => env('ETORO_BASE_URL', 'https://public-api.etoro.com'),

    'api_key' => env('ETORO_API_KEY'),

    'user_key' => env('ETORO_USER_KEY'),

    'environment' => env('ETORO_ENVIRONMENT', 'demo'),

    /*
    |--------------------------------------------------------------------------
    | Write protection
    |--------------------------------------------------------------------------
    |
    | The application is read-only by design during the MVP. This flag must
    | default to false, and App\Etoro\EtoroWriteGuard refuses to enable write
    | mode even when the flag is set to true (fail-closed).
    |
    */

    'allow_write' => env('ETORO_ALLOW_WRITE', false),

    'requests_per_minute' => env('ETORO_REQUESTS_PER_MINUTE', 45),

    'timeout_seconds' => env('ETORO_TIMEOUT_SECONDS', 20),

    'connect_timeout_seconds' => env('ETORO_CONNECT_TIMEOUT_SECONDS', 5),

    'store_raw_responses' => env('ETORO_STORE_RAW_RESPONSES', true),

    'raw_response_retention_days' => env('ETORO_RAW_RESPONSE_RETENTION_DAYS', 90),

];
