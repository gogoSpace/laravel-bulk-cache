# Invalidation

```php
use GogoSpace\BulkCache\Facades\BulkCache;

$catalog = BulkCache::scope('products-v1', ['tenant' => 17]);
$catalog->invalidateMany([101, 102]);
$catalog->invalidateScope();
```

Both methods return `void`. Item invalidation affects only the named items. Scope invalidation affects that dataset with exactly those dimensions; it does not invalidate every tenant of the dataset. Neither method flushes the Laravel store or other applications. An empty item list performs no work. Failures throw; successful return confirms the backend operations completed, subject to the selected driver's guarantees.

Call invalidation after the source transaction commits. A job dispatched before commit can read old or uncommitted source state. Laravel's after-commit scheduling prevents that ordering mistake, but cannot recover a process that dies after commit and before invalidation. Use a transactional outbox or another durable delivery mechanism when that gap is unacceptable. The [executable recovery pattern](recovery.md) stores an application-owned intent in the source transaction, uses primary-source revisions to protect reads while delivery is pending, and retries invalidation from a fresh process. A cache failure after commit does not mean the source write failed.

For user aggregates with several filters, consider filter item keys within one exact user scope, so one scope invalidation covers all variants. See the [runnable dataset patterns](dataset-design.md). If filters are dimensions instead, each complete scope needs its own invalidation; a missing dimension is never a wildcard.

The portable driver removes items or changes a scope generation. It does not atomically prevent a concurrent loader from writing after item invalidation, and its scope generation check has a race before the write. Use bounded TTLs where this is acceptable.

The Redis driver checks scope generation, item ownership, and lease validity within the publication operation. Invalidation revokes outstanding publication rights. A producer whose publication is rejected rereads or retries within its budget; its rejected source result is not returned as a successful cache value. Invalidation cannot retract an already selected response or data already sent to a client.

Scope invalidation changes a generation instead of scanning every key. Old payloads become inaccessible and reach logical expiry through their existing retention. File storage removes expired files when they are read; unreachable files may remain on disk and need operational cleanup. It is not immediate physical erasure. Scope metadata remains, so avoid generating unlimited one-off scopes. Account deletion and retention requirements need a separate data-deletion policy. The [retention procedure and runnable example](retention.md) measure owned storage, remove inventoried Redis keys in bounded batches, and retire dedicated portable stores only after an enforced producer drain and store-epoch cutover.

The cache cannot make a lagging read replica current. After a write, read from the primary or enforce an application source revision. Nor does a multi-item result represent one database snapshot: each item can come from a different source read. Cache a single aggregate or versioned snapshot when those values must be consistent together.

Use these methods for package data. Laravel `Cache::forget` with a logical item key does not address package keys, and a global backend flush bypasses coordination. Changing the application prefix starts a separate namespace; it does not physically remove the old data.
