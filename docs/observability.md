# Observing cache operations

Set `events => true` globally or for a registered dataset to dispatch `GogoSpace\BulkCache\Events\CacheEvent` through Laravel's event dispatcher. Events are disabled by default. The package has no monitoring provider dependency and does not log data itself. See the runnable [example](../examples/observability.php).

Each event has five public fields: `dataset`, `operation`, `status`, `count`, and `milliseconds`. Dataset labels come only from configured registrations; arbitrary scope names share the label `unregistered`. Keep registrations finite and use dimensions for user identity. Events never contain scope hashes, dimensions, logical keys, payloads, exception objects or exception messages. Timings use a monotonic clock; counts and durations are nonnegative.

| Operation | Meaning |
|---|---|
| `cache.connect` | Store construction and Redis primary validation duration and success/error |
| `cache.read`, `cache.claim`, `cache.publish`, `cache.release` | Backend duration and success/error; a successful command can still reject a publication |
| `cache.result` | `hit`, `miss` or `stale` counts observed while looking up a batch |
| `loader` | Source callback duration, count and success/error |
| `wait`, `retry` | Waiting and another read attempt after contention or rejected publication |
| `publication` | Rejected result count; these source values were not returned |
| `invalidation.keys`, `invalidation.scope` | Explicit invalidation duration and success/error |
| `refresh.schedule`, `refresh.dispatch` | Deferred scheduling or queue dispatch |
| `refresh.defer`, `refresh.queue` | Actual refresh duration and success/error |

Retrying reads can observe the same item more than once. These are operational observations, not unique-request billing counters. Loader duration excludes cache calls; backend timing includes the client's network work. The package reports rejected publication separately from backend failure. A network operation that throws can have an unknown mutation outcome; use the [exception contract](errors.md) for recovery decisions.

Listeners run synchronously, so keep them short and avoid network work on the critical path. A listener exception is contained and never replaces a loader, cache or cleanup exception, retries a mutation, or changes returned values. Laravel stops dispatching the remaining listeners for that individual event if one throws; the package does not recursively report that listener failure through events. Monitoring code should own its transport errors and health checks. Disabled observation does not resolve Laravel's event dispatcher for cache operations.

Some configuration failures happen before a store or valid dataset can be constructed. Handle those with application configuration checks and `bulk-cache:diagnose`; they are not cache metrics. Normal Laravel exception reporting still applies to failed deferred refreshes and failed queue jobs.
