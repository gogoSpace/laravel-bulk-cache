# Testing and measurements

From a development checkout on PHP 8.4.1 or later, install the locked development dependencies with `composer install`. On PHP 8.3, use `composer update` to resolve compatible development dependencies. Then run:

```shell
composer check
composer test:concurrency
composer smoke
composer consumer:redis
composer docs:check
composer package:check
composer benchmark:smoke
composer upgrade:check
```

`check` validates Composer metadata, style, static analysis, and unit/contract/integration tests. The concurrency command starts its own Redis service and exercises real child processes for both clients. Do not point fault tests at a shared or production Redis service.

`smoke` installs the committed ZIP archive into clean Laravel 12 and 13 applications without the package's development dependencies. It checks configuration caching, HTTP, Artisan, and queue execution. Archive checks inspect the committed distribution, so uncommitted fixes do not count as distribution evidence. `docs:check` requires the same-archive smoke result, executes the installed README quickstart and bundled examples in both consumers, and checks relative documentation links. `consumer:redis` then exercises the complete Redis suite through both clients using each installed package and framework. Its test harness supplies standalone Predis without installing it into the portable consumer, and verifies the origins of all package and framework classes. `upgrade:check` tests real beta.1 upgrades and rollback with retained data and pending jobs; see [the procedure](upgrade.md).

To install a local build manually, run `composer package:check` from a clean, committed checkout. It prints a local Composer repository URL containing the verified ZIP archive and its `packages.json` index. In a separate application, register that URL and install the development version:

```shell
composer config repositories.bulk-cache composer file:///absolute/path/to/distribution
composer require gogospace/laravel-bulk-cache:dev-main
```

`benchmark:smoke` runs the portable array-cache comparison and a real isolated Redis benchmark for PhpRedis and Predis. Missing Redis, either client, or required process extensions fails the command. Reports are written under the ignored `research/execution/next-beta/logs/` directory. `benchmark-redis.json` includes the commit, hashes of the measured runtime and harness files, versions, every sample and aggregate p95. A dirty checkout report describes that working source; its commit alone is not proof of a committed artifact.

The portable comparison uses 1, 100 and 1,000 items and an optimized Laravel `many` + batched loader + `putMany` baseline. Its repository method counts are local operations, not network exchanges. The array store and synthetic values measure local overhead, not database savings.

The Redis comparison uses 20 samples for each client, batch sizes 10 and 100, and cold, warm, half-populated and overlapping reads. Overlap starts two real PHP processes together, sharing half their keys. It verifies that guarded reads load each shared item once. The synthetic source returns deterministic values after 5 ms per loader call in both variants. The baseline uses `MGET` and one Lua batch of expiring writes; it supplies no ownership or publication guards.

The latency model delays delivery of each **real synchronous Redis response by 1 ms**, after Redis has executed the command. This emulates added round-trip latency, including consumption of lease time. It is not a production-network measurement: there is no bandwidth limit, jitter, loss or regional network model. Counters record actual raw client command invocations; each is one request/reply exchange because this harness does not pipeline. Commands executed inside Lua do not create extra network exchanges. Each measured guarded request constructs a new store and includes its `ROLE` validation, matching the public Scope path with an established Laravel connection. Fixture setup, acquiring the TCP connection and framework dispatch are excluded. Reports give this validation cost separately from engine protocol cost. Overlap reports the sum of both readers' commands and the slower reader's elapsed time.

A 20-sample development run with 100-item batches produced the following **engine protocol** p95 values with that model. This historical before/after comparison excludes the per-request `ROLE` in both versions. These are observations of this synthetic fixture, not throughput guarantees:

| Guarded scenario | Exchanges before batching | Exchanges with batching | PhpRedis p95 before / after | Predis p95 before / after |
|---|---:|---:|---:|---:|
| Cold | 302 | 5 | 456.6 / 17.5 ms | 463.3 / 17.8 ms |
| Warm | 1 | 1 | 5.5 / 2.2 ms | 6.3 / 2.6 ms |
| Half-populated | 152 | 5 | 239.9 / 20.9 ms | 238.1 / 20.9 ms |
| Two overlapping readers | 505 | 11 | 413.0 / 30.4 ms | 413.9 / 29.2 ms |

**Public request cost also includes one `ROLE` exchange per guarded call.** On an established connection that makes cold/mixed reads 6 exchanges, warm reads 2, and this two-reader overlap 13. The current report includes that construction time and command in its main totals, with separate `core_*` and `store_validation_*` fields so the historical comparison stays like-for-like. Opening a new connection adds further cost.

The unguarded many+batch baseline made two exchanges for cold/mixed, one for warm, and four across overlapping readers. It loaded 200 items in the 100+100 overlap fixture; guarded reads loaded the 150 distinct items. The benchmark checks cold/warm/mixed command budgets as well as returned values, so a regression to per-item communication fails it.

Acquiring 100 leases previously took 100 exchanges, consumed about 150/151 ms of the first 10,000 ms lease at p95, and delayed the loader by that acquisition cost. The bounded claim script took one exchange, with acquisition p95 about 3.7/2.5 ms and first-lease consumption about 3 ms for both clients. The engine still re-reads after claiming before invoking the source. This extra read, serialization, loader time, scheduling and publication must all fit within your lease and operation budgets.

Start with the default `batch_size = 100`, then measure representative payloads and actual source work. Redis scripts cap claim, publication, release and invalidation batches at 100 even when a loader chunk is larger. Larger chunks therefore consume more time before the first lease can publish and retain more payloads in memory; a larger lease is not a substitute for source timeouts. A lease that expires rejects late publication. No automatic renewal is performed, and no package-wide speedup is promised.

Fault/concurrency tests run again after batching: generation and item invalidation, expired owners, delayed cleanup against a successor, a 250-item multi-script batch, partial Lua writes, lost publication replies, failed read-only confirmation, unknown claim expiry, partial invalidation, and multiple release failures. A Lua script is atomic against other clients but is not a rollback transaction after a runtime error.

To add a store, first define its read, claim, publish, release, and invalidation behavior. A store interface alone proves no atomicity. Require the shared value/error contracts and explicit race tests before documenting support. Existing `Store` implementations can continue using individual operations. The optional `BatchStore` contract adds bulk claim/publication/release; custom adapters must preserve the same guard and error behavior. Custom adapter registration is not a public extension API in this release.
