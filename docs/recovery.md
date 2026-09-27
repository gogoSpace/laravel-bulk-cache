# Recovering a committed write

[Run the recovery scenario](../examples/recovery.php) to reproduce a database commit followed by failed Redis invalidation, a new process recovering the durable request, and duplicate delivery. Its application code is in [FavoriteApplication](../examples/Support/FavoriteApplication.php); the disposable server and process setup in `examples/Support` is only test infrastructure.

From the package checkout, or from an installed package with the example path adjusted:

```sh
php -r 'require "vendor/autoload.php"; echo json_encode(require "examples/recovery.php", JSON_PRETTY_PRINT), PHP_EOL;'
```

The scenario needs a Laravel installation, SQLite PDO, the `redis-server` executable, and PhpRedis or Predis. It starts and stops its own loopback Redis process, uses a new SQLite database under `.runtime/examples`, and leaves its disposable files there for inspection. Missing prerequisites fail the run. It does not contact an application's Redis or database. To select a client explicitly, set `$exampleRedisClient` to `phpredis` or `predis` before requiring the file.

## The application contract

The example chooses primary-source revision validation. Each user has a monotonically increasing source revision, and every favorite write records an invalidation intent in the **same database transaction** as the data change and revision increment. An intent is a durable row saying that this user's exact cache scope needs invalidation. It is owned by the application, not by the package.

A read first checks the revision and pending intents in the primary database. While any intent is pending, it reads the complete aggregate directly from the source in a database snapshot. Otherwise, it reads the cache and then checks the primary again. Every cached variant includes its source revision. A pending intent or a revision mismatch causes a **new** source read. This also covers a write racing the first check. Cached variants from different revisions are never accepted together.

The accepted result describes the source revision observed by the final validation query, or the snapshot used by the direct source read. A concurrent commit after that observation can still precede response delivery. This is not a promise that a response remains current until it reaches the client. The extra primary queries are a deliberate consistency cost; use this pattern when caching saves meaningful aggregate work. Availability of the authoritative database is required even for a cache hit.

The SQLite scenario reads revision and source rows in one transaction. When adapting it to another database, select an isolation level or query shape that supplies one consistent snapshot for both. Use the primary connection, not a lagging replica. Validate and authorize the explicit user before calling these methods. All writing paths, including SQL bulk updates, commands and other services, must participate in the revision/intent transaction; a model observer alone cannot enforce this contract.

## Save, deliver, acknowledge

`selectFavorite()` commits the selected rows, increments the user's revision and inserts the intent. Failure to insert the intent rolls back the source change too. After commit, the worker attempts `deliver()`:

1. Read the durable intent and invalidate the user's exact scope.
2. Only after invalidation returns successfully, acknowledge that particular intent row.
3. If cache invalidation throws `StoreException`, retain the pending intent. The write response still says `saved: true`, with `delivery: pending`.

The catch is limited to the invalidation attempt. Database, authorization, loader and programming errors are not turned into successful saves or silently replaced with old cache data. A clean read with a cache failure also propagates the failure in this example; the dirty-read route is an explicit source policy, not a catch-all fallback. No rejected publication result is reused as a fallback value.

A fresh delivery process discovers pending intents from the database. It needs no surviving callback or in-memory queue. A crash after invalidation but before acknowledgement leads to another delivery. Repeating scope invalidation can discard newly warmed data but cannot restore an old generation. Acknowledgement targets the intent's identifier, so replaying an older intent cannot acknowledge a newer write. Unknown mutation outcomes must also remain pending: invalidation may already have happened, and repetition is safe for this application.

In a real application, put the bounded delivery operation in an Artisan command or durable job, retry with backoff, and monitor pending count and oldest age. Schedule another pass after a process failure. The example keeps delivered intent rows to demonstrate replay; retention, batching, delivery supervision and authorization belong to the application. It does not introduce a general outbox or worker platform into the cache package.

## What the executable scenario proves

The fault disables `EVAL` only on the owned Redis instance, leaving old cached values present. A write in a separate PHP process commits successfully and fails invalidation. Reads return the new source data during the fault and after connectivity is restored but before delivery. A fresh process then discovers the persisted intent, invalidates, and acknowledges it. A later duplicate delivery still returns the updated value. This is a real backend command failure with preserved stale data, not a network-partition or Redis failover simulation.

The accompanying tests also verify transaction rollback when the intent insert fails, an older duplicate leaving newer work pending, propagation of a source query failure, and propagation of cache failure on the clean path. See [dataset design](dataset-design.md) for all filtered variants and switching implementations without addressing old legacy data.
