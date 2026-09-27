# Values and loading

```php
use GogoSpace\BulkCache\BulkCacheManager;

$cache = app(BulkCacheManager::class)->scope('labels-v1');
$values = $cache->rememberMany(['a', 'b'], 60, function (array $keys): array {
    return array_fill_keys($keys, ['label' => 'Example']);
});
// ['a' => ['label' => 'Example'], 'b' => ['label' => 'Example']]
```

Dependency injection of `BulkCacheManager` and the `BulkCache` facade provide the same API. A scope names a dataset and its context. Keep that dataset's loader meaning stable; use a new name such as `labels-v2` when the projection changes.

## Keys and results

Keys are integers or strings of at most 1,024 bytes. Integers normalize to strings before reaching the loader: `42` and `'42'` identify the same item, while `'042'` identifies another item. Binary strings are supported. Boolean, float, null, array, and object keys throw `InvalidKeyException`.

The result is a map in first-occurrence input order. Duplicates occur once. PHP represents numeric map keys such as `'42'` as integers. A generator is consumed once; `max_keys` counts all input entries, including duplicates, to bound ingestion. An empty input returns `[]` without store or loader work.

The loader receives only the keys that need loading, in chunks no larger than `batch_size`. It must return exactly those keys, in any order. It must work for any subset: do not compute an aggregate that depends on receiving the complete original request.

## Values and absence

Store finite scalars, `null`, and arrays up to 32 levels deep. Values are serialized with an internal versioned envelope; arbitrary objects, Eloquent models, resources, closures, cyclic arrays, and nonfinite numbers are rejected. Map models to arrays of the fields you need. Mutating a returned array does not change the stored value.

`null`, `false`, `0`, `''`, and `[]` are real cached values. `Missing::Value` is an explicit, distinct not-found result. Return it only when the source confirms absence. It has no stale window and lasts at most `negative_seconds` and the requested fresh duration. Invalidate it after creating the corresponding record.

The entire callback result is validated before any item from that chunk is written. An invalid output throws `LoaderException`. Earlier chunks may already be cached; a storage failure can also leave a partially written chunk. Calls are not transactions over all keys. Source exceptions propagate unchanged when cleanup succeeds.

## Freshness

`rememberMany($keys, $seconds, $loader)` accepts only fresh values. `flexibleMany($keys, Freshness::seconds($freshFor, $staleFor), $loader)` additionally permits stale values when using defer or queue refresh. `refresh: 'inline'` waits for fresh values.

Durations are integer seconds: `freshFor >= 1`, `staleFor >= 0`, with a combined maximum of 31,536,000 seconds. Invalid values throw `ConfigurationException`; zero TTL is not a bypass or invalidation operation.

Staleness starts exactly at `freshFor`. Expiration starts exactly at `freshFor + staleFor`. The age starts before the loader callback, conservatively including its execution time. A reader applies the stricter of its requested deadlines and the stored deadlines. All selected values are checked again before the result is assembled. A slow loader or another item's wait cannot make an expired value acceptable. This is a decision-time check, not a guarantee about when a network response reaches its recipient.

A blocking callback cannot be interrupted by PHP cache code. Configure database and HTTP timeouts separately; the operation budget is checked before work and after the callback returns. No automatic stale-if-error policy extends a deadline.
