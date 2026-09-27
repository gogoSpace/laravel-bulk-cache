# Changelog

## 0.1.0-beta.2

- Backend failures have a common `StoreException` contract with operation phase, mutation outcome and completed-operation count. `CleanupException` retains the primary failure and every cleanup failure without treating a loader error as a cache outage.
- Datasets can require guarded publication and mandatory typed scope dimensions. `bulk-cache:diagnose` inspects effective configuration without backend I/O or secret output.
- Optional Laravel `CacheEvent` observations report aggregate cache, loader, wait, invalidation and refresh behavior. Listener failures are contained.
- Guarded Redis operations use bounded Lua batches. Atomic generation/owner checks remain in place, with explicit partial/unknown outcomes and read-only confirmation of lost publication replies.
- Executable application examples cover durable invalidation recovery, per-user aggregates and filtered variants, shared data with personal overlays, Eloquent payload conversion, legacy cache transitions and physical retention maintenance.
- Distribution checks install exact committed archives in Laravel 12 and 13, including no-dev, config cache, HTTP, Artisan and real queue workers. Version-transition tests retain cache values and pending jobs across beta.1 upgrades and rollback.

The envelope format and refresh job version remain `1`; unchanged definitions remain compatible with beta.1. Read the [upgrade procedure](docs/upgrade.md) before enabling new definition requirements or deploying workers.

## 0.1.0-beta.1

Initial public beta: portable array/file/database caching, guarded single-primary Redis with PhpRedis or Predis, explicit scopes, bulk loading, freshness policies, invalidation and deferred/queued refresh.
