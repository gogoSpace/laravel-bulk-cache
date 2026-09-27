# Laravel Bulk Cache

Read a set of cached items and load the missing items together. Each item has its own identity and lifetime. Use explicit dimensions for tenant, user, locale, or any other input that changes the result.

The package adds `rememberMany` and `flexibleMany` alongside Laravel Cache. It includes a portable driver and a Redis driver with atomic checks before publishing loaded values. License: [MIT](LICENSE).

## Requirements and installation

PHP 8.3 or later and Laravel 12 or 13. The portable driver uses your Laravel cache store; it needs no Redis or queue worker. See [compatibility and guarantees](docs/guarantees.md) for tested environments and limits.

Install with Composer:

```shell
composer require gogospace/laravel-bulk-cache:^1.0
```

Laravel discovers the service provider automatically. Publishing configuration is optional:

```shell
php artisan vendor:publish --tag=bulk-cache-config
php artisan config:cache
```

The default uses your application's default cache store. Use a persistent store such as `file` or `database` to reuse values across requests; `array` lasts only within the process. Set a distinct `BULK_CACHE_PREFIX` for every application and environment sharing a backend.

## Quickstart

Run this in a Laravel route or Artisan command. The source is synthetic, so it needs no tables or external service. The initial scope invalidation makes the example repeatable; omit that line from normal reads.

<!-- executable: quickstart -->
```php
use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Missing;

$source = ['101' => ['name' => 'Notebook'], '102' => ['name' => 'Pencil']];
$loaderCalls = 0;
$loader = function (array $keys) use (&$source, &$loaderCalls): array {
    $loaderCalls++;
    $values = [];
    foreach ($keys as $key) {
        $values[$key] = $source[$key] ?? Missing::Value;
    }

    return $values;
};

$catalog = BulkCache::scope('quickstart-catalog-v1', ['locale' => 'en']);
$catalog->invalidateScope();
$first = $catalog->rememberMany([101, 102, 999], 60, $loader);
$second = $catalog->rememberMany([101, 102, 999], 60, $loader);

$source['101'] = ['name' => 'Updated notebook'];
$catalog->invalidateMany([101]);
$updated = $catalog->rememberMany([101, 102], 60, $loader);

return [
    'same_values' => $first === $second,
    'missing' => $second[999] === Missing::Value,
    'updated_name' => $updated[101]['name'],
    'loader_calls' => $loaderCalls,
];
```

The result is `same_values: true`, `missing: true`, `updated_name: "Updated notebook"`, and `loader_calls: 2`. The second read needs no source call; invalidating item 101 reloads only that item. The same example is available as [a PHP file](examples/quickstart.php).

In a database loader, use one `whereIn` query per callback and map its rows to every requested key. Return `Missing::Value` for an authoritative absence. A missing map key is an error. A timeout or failed query must throw; it must not become `Missing::Value`.

## Refreshing older values

`flexibleMany` can return stale values while scheduling a refresh. “Stale” means older than the fresh duration but still inside an explicitly allowed extra window.

```php
use GogoSpace\BulkCache\Freshness;

$values = $catalog->flexibleMany(
    [101, 102],
    Freshness::seconds(freshFor: 60, staleFor: 300),
    $loader,
);
```

This allows at most 60 fresh seconds plus 300 stale seconds. Missing or expired items load synchronously. Automatic refresh runs after an HTTP response below status 400; CLI calls refresh inline. See [HTTP and queue lifecycle](docs/lifecycle.md) before using deferred refresh or queue workers.

## Choosing where to use it

Start with an ordinary batch database query. Caching is useful when repeated reads avoid significant source work. It also adds storage operations, invalidation work, and a period in which data may be old. A cheap query or mostly unique requests may be better without caching.

Complete synthetic examples show [a public catalog with per-user overlays](examples/catalog.php) and [an expensive API or read model](examples/read-model.php). See [how to adapt them](docs/examples.md).

## Documentation

- [Values, loaders, freshness, and limits](docs/usage.md)
- [Configuration and stores](docs/configuration.md)
- [User and tenant scope](docs/scopes.md)
- [Invalidation and source consistency](docs/invalidation.md)
- [HTTP defer and queue loaders](docs/lifecycle.md)
- [Guarantees and compatibility](docs/guarantees.md)
- [Errors and troubleshooting](docs/errors.md)
- [Testing and measurements](docs/testing.md)
