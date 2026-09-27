# Errors and troubleshooting

The default is to throw on source and configuration failures, and on storage failures exposed by the store API. An exposed storage failure is not treated as a cache miss. Laravel FileStore itself converts some unreadable or corrupt files to misses; the portable adapter cannot recover error information swallowed by Laravel. The package does not silently bypass a failed Redis guard or fan requests out to the origin.

| Exception | Typical cause | Action |
|---|---|---|
| `InvalidKeyException` | Invalid key, dimension, or input size | Validate or split input; keep identity explicit |
| `ConfigurationException` | Unsupported store, bad option, or invalid refresh strategy | Correct the configuration; do not catch and silently downgrade |
| `LoaderException` | Missing/extra output keys, unsupported value, or oversized envelope | Return a complete map of scalar/array projections |
| `RecursiveLoadException` | Loader requests an identity already loading in this process | Remove the dependency cycle or use a separate aggregate |
| `StoreException` | Malformed data or an unconfirmed backend operation | Investigate storage; invalidate through the package after repair |
| `TimeoutException` | Wait or operation budget exhausted | Inspect source timeouts, lease lengths, contention, and batch size |

Classes are in `GogoSpace\BulkCache\Exceptions`. Underlying source and client exceptions can also propagate. Cleanup failures can supersede an earlier exception. Application logs may contain messages from your loader or client; avoid putting secret values in those exceptions.

**Values reload on every HTTP request.** Check whether the selected store is `array`. It provides no persistence across separate PHP processes. Also check prefix and dimensions for accidental variation.

**A null value is loaded repeatedly.** Return an explicit key mapped to `null`. Omitting the key is a loader error. `Missing::Value` is a separate short-lived absence marker.

**Deferred refresh does not run.** Ensure the Laravel HTTP kernel is terminated normally. The provider installs the package middleware, including on traditional kernels. Refresh runs only for status codes below 400. Explicit `defer` outside that lifecycle throws; use `inline` in a command. A process killed after sending the response can lose deferred work.

**A queue does not refresh values.** Use a registered `Loader` class, omit the callback, and run a worker for the configured connection and queue. Inspect failed jobs. Cached data still expires at its original deadline; queueing does not extend it. A sync queue executes during the request and does not provide asynchronous refresh.

**An old job fails after deployment.** Jobs include a dataset definition fingerprint and protocol version. A changed definition is rejected. Restart workers after deployment and discard or explicitly redispatch obsolete refresh jobs.

**Invalidation appears ineffective.** Use exactly the same dataset and dimension types as the reader, invalidate after commit, and check whether the loader reads a lagging source. Portable item invalidation can race with an in-flight loader; choose guarded Redis when that race is unacceptable.
