# Guarantees and compatibility

This is a beta release. The behavior below describes the implemented contract and tested scenarios; it does not establish production maturity. The public API and stored cache format may change before 1.0.

| Behavior | Portable | Redis |
|---|---|---|
| Per-item values, bounded loader chunks, explicit scope | Yes | Yes |
| Distinct null, false, empty value, and not-found | Yes | Yes |
| Reader freshness and final age check | Yes | Yes |
| Coalesce overlapping loads across processes | No | Per-key execution lease |
| Reject publication after ownership loss or invalidation | No atomic guarantee | Atomic server check |
| Reject publication after lease expires without a replacement owner | No | Yes |
| Atomic snapshot across every requested item | No | No |
| Exactly-once source execution | No | No |
| Durable source-to-cache transaction or rollback-proof failover | No | No |

A lease is temporary permission to publish one item's loaded value. The Redis driver checks the current scope generation, owner token, and live lease in the same Redis operation that writes the payload. Expired or revoked producers cannot overwrite the accepted value. Ownership is released only by its current owner. Requested guarantees are never silently downgraded to portable writes.

Redis guards apply to one authoritative primary's current history. Replication rollback, failover that loses accepted writes, or multiple independent primaries can lose cache history. They do not establish source database consistency, a durable transaction, or exactly-once work. Source calls should be read-only or independently idempotent. The application must authorize results and coordinate source versions where required.

Waits and loading windows are bounded. A lost or expired producer may lead to repeated source calls. Publication rejection leads to a reread or bounded retry, not successful delivery of that rejected source result. An uncertain backend response throws unless the adapter can verify its own accepted publication; it does not justify an unguarded write.

Freshness timestamps use the producer PHP process's wall clock and are compared with the reader's wall clock. Synchronize clocks across application hosts. Clock skew or a backward wall-clock jump can extend apparent freshness; monotonic operation budgets do not remove that limitation. Redis ownership expiry uses Redis's own clock.

## Tested configurations and limits

PHP constraints are `^8.3`; Illuminate components allow Laravel 12 and 13. Local execution uses PHP 8.4.7. The contract suite runs Laravel array, file, and database cache stores; SQLite is the verified database engine. Redis 7.2 is the verified server baseline: the suite runs an isolated Redis 7.2.8 primary separately through PhpRedis and Predis. Older Redis releases have not been tested. Local Redis client execution used PhpRedis 6.3.0 and Predis 3.6.1 with PHP 8.4.7. The complete Redis scenario suite also passed against the extracted package installed in Laravel 12.69.2 and 13.33.0; package and framework class origins were verified in each consumer. Separate consumer processes ran without Redis clients for portable, HTTP, and queue verification. These tests do not represent every combination of Laravel, PHP, and Redis.

The Redis adapter uses raw commands and does not change client options on a shared connection. Tests cover PhpRedis serializers NONE, PHP, and JSON with compression NONE, plus client prefixes and Predis prefixes. Additional serializer and compression extensions have not been verified.

Redis Cluster, Sentinel/replication client connections, sharded clients, read replicas, Memcached, DynamoDB, custom Laravel stores, and custom Redis key processors are unsupported. Unsupported activated configurations fail rather than gaining a claimed guard. Managed Redis deployments need the commands used by the protocol, including `EVAL` and `ROLE`; restricted command policies can fail explicitly.

Clean archive consumers cover Laravel 12 and 13 with package discovery, no-dev installation, configuration caching, HTTP, Artisan, queue workers, and the documented examples. Passing those local tests does not establish support for every PHP patch, database engine, or hosting environment. No Octane runtime or cross-region guarantee is claimed. The repository configures a PHP 8.3/8.4/8.5 CI matrix; those remote jobs are not part of the executed local evidence.

Portable storage inherits Laravel store error behavior. In particular, FileStore can turn unreadable or corrupt files into cache misses before the package sees them. Explicit exceptions, incomplete bulk responses, malformed package envelopes, and false write confirmations are still treated as failures.

## Metadata and retention

Item payloads have logical expiry. Scope generation metadata can outlive them, and scope invalidation can leave unreachable payloads. Redis expires payload keys; file caches may leave expired, unread files on disk until separate cleanup. The prefix and dimensions must therefore have deliberate, bounded cardinality. Invalidation is a visibility operation; it is not guaranteed physical deletion of every historical byte.

Changing driver creates a separate namespace. Changing the loader class without changing the dataset identity does not invalidate values. Use explicit versioned dataset names or version dimensions when deploying a different data contract.
