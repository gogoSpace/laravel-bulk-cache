# Errors and troubleshooting

The default is to throw on source and configuration failures, and on storage failures exposed by the store API. An exposed storage failure is not treated as a cache miss. Laravel FileStore itself converts some unreadable or corrupt files to misses; the portable adapter cannot recover error information swallowed by Laravel. The package does not silently bypass a failed Redis guard or fan requests out to the origin.

| Exception | Typical cause | Action |
|---|---|---|
| `InvalidKeyException` | Invalid key, dimension, or input size | Validate or split input; keep identity explicit |
| `ConfigurationException` | Unsupported store, bad option, or invalid refresh strategy | Correct the configuration; do not catch and silently downgrade |
| `LoaderException` | Missing/extra output keys, unsupported value, or oversized envelope | Return a complete map of scalar/array projections |
| `RecursiveLoadException` | Loader requests an identity already loading in this process | Remove the dependency cycle or use a separate aggregate |
| `StoreException` | Malformed data or an exposed backend failure | Inspect `phase`, `outcome`, and the previous exception; retry invalidation through the package after recovery |
| `CleanupException` | One or more ownership releases failed | Inspect `primaryFailure` and every entry in `cleanupFailures` before choosing recovery |
| `TimeoutException` | Wait or operation budget exhausted | Inspect source timeouts, lease lengths, contention, and batch size |

Classes are in `GogoSpace\BulkCache\Exceptions`. Built-in portable and Redis adapters wrap exposed client failures in `StoreException` and retain the original cause in `getPrevious()`. A source exception remains the exact original exception when cleanup succeeds. If cleanup also fails, `CleanupException` retains the source/publication exception as `primaryFailure` and as its previous exception, plus **all** known cleanup failures. Cleanup continues after a release error. A cleanup-only error has `primaryFailure === null`. `CleanupException` does not extend `StoreException`: catching a cache outage must not accidentally classify a source failure as safe for fallback. Custom `Store` implementations remain responsible for their error contract.

`StoreException::phase` identifies `connect`, `read`, `claim`, `publish`, `invalidate`, or `release`. The default `unknown` remains available for existing custom adapters. `outcome` describes the failing storage operation:

| Outcome | Meaning |
|---|---|
| `not_applicable` | A read failed; there is no mutation to confirm |
| `not_applied` | The failed mutation was not applied, or connection setup failed before it |
| `unknown` | A mutation may have reached Redis/the Laravel backend before the response failed |
| `partial` | Some item operations were confirmed; the failing operation can still have an unknown result |

`completedOperations` counts confirmed item operations in a partially completed call, not Redis commands. It does not identify individual keys, and confirmed operations need not form a contiguous prefix. A lost reply can mean more operations completed than this count. Earlier writes are not rolled back. Wrapper exceptions preserve the original cause through the previous-exception chain. On a bulk read, confirmed publications in earlier chunks remain included when a later cache read, claim or publication fails; `phase` still names the failing phase. Successful chunks before a later source error also remain cached, but that source exception is not reclassified.

Redis publishes at most 100 items per script. Lua prevents concurrent interleaving but does not roll back earlier writes if a later item fails. After a publication error, the adapter makes a read-only check for the exact generation, owner and payload. It returns success only when every item in that failed batch can be confirmed. Otherwise the original publication error remains, with partial progress where known; neither the loader nor the mutation is automatically repeated. After a later claim batch fails, earlier acknowledged claims are released and any cleanup failures are preserved. An unacknowledged claim in the failed batch can leave a live lease until its original expiry. There is no automatic lease renewal.

Automatic catch-all source fallback is not provided. An application may make a **new authorized read** from its authoritative source under its own consistency policy after a recognized cache failure. It must inspect a combined cleanup error's primary cause first and must never return a loader value rejected by the publication guard.

Application logs may contain messages from your loader or client; avoid putting secret values in those exceptions.

**Values reload on every HTTP request.** Check whether the selected store is `array`. It provides no persistence across separate PHP processes. Also check prefix and dimensions for accidental variation.

**A null value is loaded repeatedly.** Return an explicit key mapped to `null`. Omitting the key is a loader error. `Missing::Value` is a separate short-lived absence marker.

**Deferred refresh does not run.** Ensure the Laravel HTTP kernel is terminated normally. The provider installs the package middleware, including on traditional kernels. Refresh runs only for status codes below 400. Explicit `defer` outside that lifecycle throws; use `inline` in a command. A process killed after sending the response can lose deferred work.

**A queue does not refresh values.** Use a registered `Loader` class, omit the callback, and run a worker for the configured connection and queue. Inspect failed jobs. Cached data still expires at its original deadline; queueing does not extend it. A sync queue executes during the request and does not provide asynchronous refresh.

**An old job fails after deployment.** Jobs include a dataset definition fingerprint and protocol version. A changed definition is rejected. Restart workers after deployment and discard or explicitly redispatch obsolete refresh jobs.

**Invalidation appears ineffective.** Use exactly the same dataset and dimension types as the reader, invalidate after commit, and check whether the loader reads a lagging source. Portable item invalidation can race with an in-flight loader; choose guarded Redis when that race is unacceptable.
