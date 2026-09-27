# User and tenant scope

Every input that changes the cached result belongs in the scope dimensions. The package never reads the current authenticated user for you.

```php
use GogoSpace\BulkCache\Facades\BulkCache;

$tenantIdentifier = 17;
$userIdentifier = 42; // Copy the authorized application's actual subject.
$preferences = BulkCache::scope('preferences-v1', [
    'tenant' => $tenantIdentifier,
    'user' => $userIdentifier,
    'locale' => 'en',
]);
$values = $preferences->rememberMany(['theme'], 60, function (array $keys): array {
    return array_fill_keys($keys, 'dark');
});
// ['theme' => 'dark']
```

Dimensions are named strings with string, integer, boolean, or null values. Their order does not matter, and their types do: tenant `17` differs from tenant `'17'`. Use at most 32 dimensions; names are at most 200 bytes and string values at most 1,024 bytes. Nested arrays and floats are rejected with `InvalidKeyException`. Convert compound context to an explicit stable version or canonical identifier yourself. Dataset names must contain 1–200 bytes.

Share public data under a public scope and cache user-specific flags separately. The [catalog example](../examples/catalog.php) reuses one catalog for Alice, Bob, and a guest while isolating their favorites. If guests have personalized sessions, include an explicit session identifier; one shared `guest` identity is suitable only for identical guest results.

Authorization still belongs to the application. Check access before returning protected data. Neither an explicit user dimension nor a recently cached permission proves that access remains allowed. Queue loaders receive serialized dimensions, not a request or the worker's ambient authentication state. Decide whether current authorization must be rechecked inside the loader.

Include locale, currency, schema version, source revision, or feature variant when it affects the result. Prefixes separate applications and environments; dimensions separate data inside an application. Avoid putting secrets or unnecessary personal data in dimensions even though physical scope identifiers are hashed.

## Require an identity shape

Registered datasets can add a small schema in `config/bulk-cache.php`: `dimensions => ['tenant' => 'int', 'user' => 'int']`. Every listed dimension is required and must have that exact type. Supported types are `int`, `string` and `bool`; null and missing fields fail. Additional dimensions keep the normal identity rules above. An incorrect schema or scope raises `ConfigurationException` at `scope()`, before cache I/O, even when the requested key list is empty.

The executable [observation example](../examples/observability.php) uses a required integer user dimension. Schema validation prevents accidental omission; it does not decide whether the caller is allowed to read that user's data. New schema requirements deliberately change the queued dataset definition; use the [upgrade procedure](upgrade.md) for pending jobs.
