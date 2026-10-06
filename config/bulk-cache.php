<?php

return [
    'driver' => env('BULK_CACHE_DRIVER', 'portable'),
    'store' => env('BULK_CACHE_STORE'),
    // Set a unique prefix for each application and environment sharing a store.
    'prefix' => env('BULK_CACHE_PREFIX', env('APP_KEY')
        ? 'application:'.hash('sha256', serialize([env('APP_NAME', 'laravel'), env('APP_ENV', 'production'), env('APP_KEY')]))
        : null),
    'connection' => env('BULK_CACHE_REDIS_CONNECTION', 'default'),
    // Fail before I/O if a dataset requires atomic publication guards.
    'require_guarded' => false,
    // Required scope dimensions: ['tenant' => 'int', 'user' => 'int'].
    'dimensions' => [],
    // Dispatch safe, aggregate CacheEvent observations through Laravel events.
    'events' => false,
    // Maximum keys in each loading window.
    'batch_size' => 100,
    // Maximum input entries per call, including duplicates.
    'max_keys' => 10000,
    // Maximum serialized cache envelope per item, in bytes.
    'max_payload_bytes' => 1048576,
    // Maximum age of explicit Missing::Value results, in seconds.
    'negative_seconds' => 15,
    // Redis producer ownership lifetime, in milliseconds. No automatic renewal.
    'lease_milliseconds' => 10000,
    // Cumulative polling sleep budget, in milliseconds.
    'wait_milliseconds' => 2000,
    // Cooperative operation budget, including loader time, in milliseconds.
    // Configure source timeouts separately. This cannot interrupt blocking I/O.
    'operation_milliseconds' => 15000,
    'queue_connection' => null,
    'queue' => null,
    // Each entry supplies a Loader class and may override the options above.
    'datasets' => [],
];
