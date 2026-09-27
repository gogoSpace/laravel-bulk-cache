# Testing and measurements

From a development checkout on PHP 8.4.1 or later, install the locked development dependencies with `composer install`. On PHP 8.3, use `composer update` to resolve compatible development dependencies. Then run:

```shell
composer check
composer test:concurrency
composer smoke
composer docs:check
composer package:check
composer benchmark:smoke
```

`check` validates Composer metadata, style, static analysis, and unit/contract/integration tests. The concurrency command starts its own Redis service and exercises real child processes for both clients. Do not point fault tests at a shared or production Redis service.

`smoke` installs the committed ZIP archive into clean Laravel 12 and 13 applications without the package's development dependencies. It checks configuration caching, HTTP, Artisan, and queue execution. Archive checks inspect the committed distribution, so uncommitted fixes do not count as distribution evidence. `docs:check` executes the README quickstart and bundled examples and checks relative documentation links.

To install a local build manually, run `composer package:check` from a clean, committed checkout. It prints a local Composer repository URL containing the verified ZIP archive and its `packages.json` index. In a separate application, register that URL and install the development version:

```shell
composer config repositories.bulk-cache composer file:///absolute/path/to/distribution
composer require gogospace/laravel-bulk-cache:dev-main
```

`benchmark:smoke` measures 1, 100, and 1,000 items on an array cache with deterministic scalar/array payloads. It compares portable package calls against optimized Laravel `many` plus a batched loader and `putMany`, for cold and warm reads. It reports cache method operations, loader calls, loaded items, elapsed time, and peak memory. The baseline does not provide Redis publication guards, and the benchmark makes no comparison of those guarantees.

Method-operation counts are not network round trips; Laravel stores can implement a bulk method with individual writes. The synthetic source does almost no work, so measured times mainly show local overhead. Use representative database/API work and your actual storage latency before drawing performance conclusions. No percentage speedup is promised.

In the local 1,000-item fixture with a batch size of 100, the portable package made 3,044 repository method calls for a cold read, compared with 20 for the optimized baseline. Warm reads made 20 and 10 calls respectively. Both variants invoked the loader 10 times when cold and zero times when warm. These counts include nested repository calls and scope metadata operations. The package adds freshness, identity, and publication bookkeeping; this fixture shows higher cache-operation overhead, not a speed advantage. Counts can change with implementation or store behavior; rerun the tool for the current source.

To add a store, first define its read, claim, publish, release, and invalidation behavior. A store interface alone proves no atomicity. Require the shared value/error contracts and explicit race tests before documenting support. Custom adapter registration is not a public extension API in this release.
