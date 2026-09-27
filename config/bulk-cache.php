<?php

return [
    'driver' => env('BULK_CACHE_DRIVER', 'portable'),
    'store' => env('BULK_CACHE_STORE'),
    // Set a unique prefix for each application and environment sharing a store.
    'prefix' => env('BULK_CACHE_PREFIX', env('APP_KEY')
        ? 'application:'.hash('sha256', serialize([env('APP_NAME', 'laravel'), env('APP_ENV', 'production'), env('APP_KEY')]))
        : null),
    'connection' => env('BULK_CACHE_REDIS_CONNECTION', 'default'),
    'batch_size' => 100,
    'max_keys' => 10000,
    'max_payload_bytes' => 1048576,
    'negative_seconds' => 15,
    'lease_milliseconds' => 10000,
    'wait_milliseconds' => 2000,
    'operation_milliseconds' => 15000,
    'queue_connection' => null,
    'queue' => null,
    // Each entry supplies a Loader class and may override the options above.
    'datasets' => [],
];
