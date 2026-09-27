# Examples

All examples return an array and use synthetic data. The quickstart, catalog and read-model examples expect a bootstrapped Laravel application and invalidate only their own example scope so repeated runs have the same result. Remove the initial invalidation from real read paths. The recovery, dataset-design and retention scenarios bootstrap their own isolated SQLite and Redis environment; they require Laravel, SQLite PDO, `redis-server` and either PhpRedis or Predis, and fail when a prerequisite is missing.

## Public catalog and personal overlay

[Catalog example](../examples/catalog.php) reads the shared catalog once for Alice, Bob, and a guest. Favorites have explicit user dimensions. The result contains one public loader call, Alice's favorite notebook, Bob's favorite pencil, and no guest favorites.

In a real loader, perform one batch query for the requested product identifiers. Fetch only public display fields. Load favorites separately with both the tenant and user condition. Authorize protected data before returning it, and do not mark a personalized HTTP response as publicly cacheable merely because part of its internal data is shared.

A favorite change invalidates that user's overlay item. A product rename invalidates the public item. These changes do not need to erase every user's whole catalog. If a guest's results depend on a session, the session also belongs in the dimensions.

## API or read model

[Read-model example](../examples/read-model.php) caches per-item order metrics with a 60-second fresh period and 300 additional stale seconds. It uses `inline` to run in HTTP, commands, and test scripts without an external scheduler. Repeated fresh reads call the source once.

Replace the synthetic loader with one bounded bulk API call or aggregate query. Set an explicit HTTP/database timeout. Let failures throw; do not turn a timeout into a cached not-found value. For HTTP stale responses use automatic defer; for durable queue transport register the [loader class](lifecycle.md).

If the upstream API has no batch endpoint, the package cannot turn individual API requests into one upstream request. If all metrics must describe one source snapshot, cache the complete aggregate as one item or use an explicit source snapshot revision.

## Committed writes, aggregates and safe rollback

The [recovery example](recovery.md) commits source data and a durable invalidation intent together, reproduces a failed Redis invalidation, reads safely while the intent remains pending, and retries from a fresh process with duplicate delivery.

The [dataset design example](dataset-design.md) covers a complete per-user aggregate including an empty result, all filtered variants in one exact scope, bulk writes from another process, shared product data with a personal overlay, Eloquent-to-scalar conversion, and switching legacy/bulk reads without returning old legacy data. These are application policies built on the existing package API.

The [retention example](retention.md) measures actual key/file/row storage, performs bounded physical removal, rejects a held old Redis producer after generation erasure, and drains portable producers before retiring their dedicated store.

## Deciding whether to cache

For a cheap `whereIn` query, an ordinary database batch may be simpler and faster. Compare complete request cost, repeat frequency, and source work. The [benchmark tool](testing.md) compares this package with optimized `many` plus a batch loader; it does not assume that fewer loader calls guarantee lower total latency.
