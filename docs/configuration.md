# Configuration and stores

Publish `config/bulk-cache.php` with `php artisan vendor:publish --tag=bulk-cache-config`. Scalar options are compatible with `php artisan config:cache`; registered loaders are class names, never configuration closures.

| Option | Default | Meaning |
|---|---|---|
| `driver` | `portable` | `portable` or guarded `redis` |
| `store` | `null` | Laravel cache store; null uses the application default |
| `prefix` | Hash derived from `APP_NAME`, `APP_ENV`, and `APP_KEY` | Separate application namespace; explicit `BULK_CACHE_PREFIX` overrides it |
| `connection` | `default` | Laravel Redis connection for the Redis driver |
| `batch_size` | `100` | Maximum keys in a loading window; up to 1,000 |
| `max_keys` | `10000` | Maximum input entries per call; up to 100,000 |
| `max_payload_bytes` | `1048576` | Maximum encoded envelope per item; up to 16 MiB |
| `negative_seconds` | `15` | Maximum age for explicit not-found values |
| `lease_milliseconds` | `10000` | Redis producer ownership lifetime; no automatic renewal |
| `wait_milliseconds` | `2000` | Cumulative polling wait budget |
| `operation_milliseconds` | `15000` | Overall cooperative operation budget, including loader time |
| `queue_connection` | `null` | Laravel queue connection; null uses its default |
| `queue` | `null` | Queue name; null uses the connection's default |
| `datasets` | `[]` | Named loader registrations and per-dataset option overrides |

Numeric options must be positive integers. Invalid configuration throws `ConfigurationException`. Maximum lease and operation budgets are one hour; waiting is limited to ten minutes. These limits do not configure network or database timeouts.

The default prefix requires a nonempty application key. Without one, set an explicit `BULK_CACHE_PREFIX`; missing identity fails with a configuration error. Replicas of the same application must use the same key and prefix. Rotating the application key changes the default namespace, causes cold reads, and leaves older payloads until expiry. Use distinct explicit prefixes when applications share an application key.

## Portable stores

Set `BULK_CACHE_DRIVER=portable` and optionally `BULK_CACHE_STORE=file` or `database`. Supported Laravel stores are `array`, `file`, and `database`. Database storage needs Laravel's cache table. Tests use SQLite; other SQL engines are not independently verified. Custom stores, Redis-as-portable-store, Memcached, and DynamoDB are rejected instead of silently gaining unsupported guarantees.

Array storage is local to a PHP process. File storage needs a writable cache directory and only shares values where processes share that filesystem. Laravel FileStore converts some unreadable or corrupt files to misses, so the portable driver cannot reliably distinguish those filesystem failures from absent data. Database storage shares through its configured table. Portable behavior has no distributed producer exclusion or atomic publication guard. See [guarantees](guarantees.md).

## Redis

```dotenv
BULK_CACHE_DRIVER=redis
BULK_CACHE_PREFIX=my-application:production
BULK_CACHE_REDIS_CONNECTION=cache
```

Configure the `cache` connection under Laravel's `database.redis`. The driver uses that Redis connection directly; Laravel's cache-store name and cache prefix do not select its data. The package prefix and the Redis connection's own prefix both apply.

Use a single authoritative Redis primary with either PhpRedis or Predis. Predis is optional and belongs in the consuming application's dependencies if selected. The package does not change global client options. See [the verified client options and topology limits](guarantees.md) before enabling it.

Keep normal source calls comfortably below the lease and operation budgets. A lease can expire during blocked I/O; late publication is rejected, and another producer may repeat the source call. There is no exactly-once execution guarantee or automatic background lease renewal.
