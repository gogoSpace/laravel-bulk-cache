# Retention and physical removal

Logical invalidation makes a value ineligible for future cache reads. It does not promise to erase every stored byte. Redis payloads and leases have expiration, while scope generation metadata persists. Portable file storage can retain expired or unreachable files until a separate cleanup removes them. Changing a dataset version or application prefix does not delete the old namespace.

[Run the retention scenario](../examples/retention.php) to inspect real storage counts and sizes, remove exact Redis keys in bounded batches, and retire dedicated file and SQLite cache stores after their producers finish. It also holds an old Redis producer in a separate process across erasure and generation recreation, and proves that it cannot publish either time.

```sh
php -r 'require "vendor/autoload.php"; echo json_encode(require "examples/retention.php", JSON_PRETTY_PRINT), PHP_EOL;'
```

It has the same isolated Redis/SQLite requirements as the [recovery scenario](recovery.md). It creates only synthetic data in its owned server and directory. Every mutation batch is limited to two entries in this demonstration, and failed requirements or assertions fail the command. Supporting maintenance classes are application examples, not a new public package cleanup API.

## Inventory and growth measurements

Maintain a bounded inventory of the datasets, complete dimension combinations, application prefixes and logical item keys your application owns. It must come from your application records or a durable inventory of writes; the cache cannot infer all historical users, dimensions or old versions for you. Avoid per-request random scopes that make this inventory grow without bound.

[RedisInventory](../examples/Maintenance/RedisInventory.php) derives the exact value, lease and generation keys for that inventory. `sample()` uses `MEMORY USAGE` only for those keys and returns the number inventoried, the number still present and their Redis memory bytes. Sample the same inventory periodically and compare metadata count with the number of active scopes. Record growth when deploying new dataset versions and remove retired inventory only after the cleanup is verified. Redis allocator overhead means these bytes are not a count of application payload bytes; sampling an active scope is advisory, not a consistent database snapshot.

The helper intentionally matches the currently tested v1 physical layout and refuses a client key prefix. It is **version-specific maintenance code**, not a stable addressing API. When adapting it, verify the exact deployed format and connection prefix. It never uses `KEYS`, a database-wide `SCAN`, or a flush. Its report describes only inventoried keys: it makes no claim to discover or delete unknown historical scopes or unrecorded keys.

For retired portable stores the example reports file count and bytes, or row count and key/value bytes in the dedicated SQLite table. Whole-store counting is appropriate only for an owned retired directory/table. For a large deployment, schedule such measurement separately from latency-sensitive reads; the deletion mutation budget does not bound the cost of a complete size census.

## Redis: remove a known user or retired version

For account deletion, first prevent new work for the deleted user, remove or revoke access to its source data, and stop queued work from reopening that user scope. For a retired cache version, redirect new work to the new version/namespace and account for old workers and queued jobs before cleanup. These are application gates. Merely deleting Redis metadata while unrestricted producers continue using the same namespace is not a retirement protocol.

Once new work is excluded, revoke existing publication rights with `invalidateScope()`, then remove the inventoried generation, payload and lease keys in bounded batches. Keep the cursor and retry incomplete batches. Backend errors throw before the helper returns an advanced cursor, including PhpRedis errors reported as `false` plus `getLastError()`. Retry an unknown outcome from the same cursor; `DEL` of an already removed key is harmless. The sample includes generation metadata in physical deletion, rather than leaving its growth unaddressed.

The executable scenario allows one known old producer to remain paused after its claim. It removes every inventoried user key and measures zero remaining bytes. Publication with the old claim is rejected both while generation metadata is absent and after a new generation is deliberately created for the test. An independent user and another application on the same Redis remain readable. The temporary recreated probe values are then removed too. This demonstrates the guarded protocol's late-publication behavior; it does not authorize new production activity for a deleted account.

To remove an old version, repeat the same procedure with its **complete owned inventory and original prefix**. Do not invent a wildcard scope or delete a shared prefix discovered by scanning an unrelated database. If you do not have an adequate historical inventory, use an operationally dedicated Redis namespace/instance retirement plan whose entire contents you own, and verify the producers are drained; this example does not claim per-user erasure of unknown keys.

## Portable: drain, change store epoch, remove the dedicated store

Portable mode has no atomic guard against a producer writing after invalidation. Active metadata deletion can therefore revive an old generation or leave a late payload. Do not use the guarded Redis procedure as a guarantee for portable stores.

[DedicatedPortableStore](../examples/Maintenance/DedicatedPortableStore.php) demonstrates a narrower alternative for file and SQLite database cache. Every cache operation holds the same application lock across claim, source loading, publication and release. Each operation reads the active store epoch while holding that lock. The retirement operation takes an exclusive lock, which cannot succeed while a reader/producer is active, then changes both the active dedicated directory/table and the namespace epoch. Subsequent operations use the new store. Cleanup removes the retired store in bounded batches, including its generation metadata, empty directory structure or final table.

The scenario holds a real portable loader in another process and proves retirement is refused until it finishes. Its late payload is included in the old store's measured contents, then physically deleted. The old directory/table is absent afterwards; fresh reads use epoch 2. Another application's directory/table remains intact. The helper also tolerates a restart after an empty next store was prepared but before the epoch switch, and after the retired store was removed but before completion was recorded. It refuses to reuse a prepared next store containing data. These boundaries have focused restart-state tests.

This file lock is suitable for the demonstrated single-host process group. All participants must use the wrapper and the same lock, must not retain a scope outside its callback, and must complete deferred or queued work before retirement. Multi-host workers need a shared coordination mechanism or an enforced maintenance drain; a local lock does not synchronize other hosts. Plan restart/retry handling for the application's epoch deployment before using it in production.

Portable keys from historical random scope generations cannot generally be reconstructed from only a user identifier. When precise per-user historical inventory is unavailable, this example erases the **whole dedicated retired store**, including other users' cached values in that store, and reloads authorized users in a new epoch. That is a deliberate operational cost, not a selective per-user purge claim. Never substitute a shared Laravel cache directory/table containing unrelated application data.

## Source data and other retained copies

Cache cleanup does not erase database source rows, durable invalidation history, database backups, Redis persistence files, logs or responses already sent to clients. Include those copies in the application's deletion policy. Stop reuse of obsolete code/configuration and queued jobs that can address retired namespaces. TTL, logical invalidation, namespace cutover and verified physical removal solve different parts of retention; report which one has actually completed.
