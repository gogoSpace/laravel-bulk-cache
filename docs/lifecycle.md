# HTTP and queue lifecycle

## Automatic and deferred refresh

`flexibleMany` with `refresh: 'auto'` uses deferred refresh inside the package's HTTP middleware and inline refresh elsewhere. The service provider installs that middleware on Laravel's HTTP kernel, including traditional application kernels. Applications must still terminate the kernel normally after sending a response.

A fresh hit returns without loading. A stale hit returns the previous value and schedules source work after response termination. A cold or expired item loads synchronously. Mixed requests load the cold subset without waiting for stale refresh. The final age check may turn a stale item into a synchronous miss if another source call consumed its remaining lifetime.

Deferred work runs for HTTP status codes below 400, including 200 and 302. It is discarded for 404 and 500. There is no `always` mode. Deferred exceptions are reported through Laravel's exception handler after the response; the original cache deadline remains unchanged. A killed process can lose deferred work, so this is best effort, not durable delivery.

Use `refresh: 'inline'` to require a fresh result before returning. Use `refresh: 'defer'` only within the HTTP lifecycle; outside it the package throws `ConfigurationException`. Automatic refresh in Artisan, queue jobs, or standalone commands is inline, so it does not accumulate callbacks until process exit.

## Registered queue loader

A registered loader is a container-resolved class implementing `Loader`. Put this example in `app/BulkCache/OrderMetricsLoader.php`:

```php
<?php

namespace App\BulkCache;

use GogoSpace\BulkCache\Contracts\Loader;

final class OrderMetricsLoader implements Loader
{
    public function load(array $keys, array $dimensions): array
    {
        // Replace with one batch query filtered by $dimensions['tenant'].
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = ['completed' => (int) $key * 3];
        }

        return $values;
    }
}
```

In `config/bulk-cache.php`, add a dataset entry. Use the application's configured queue connection and an actively consumed queue:

```php
'datasets' => [
    'order-metrics-v1' => [
        'loader' => App\BulkCache\OrderMetricsLoader::class,
        'queue_connection' => 'database',
        'queue' => 'cache-refresh',
    ],
],
```

Then read without a callback:

```php
use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Freshness;

$values = BulkCache::scope('order-metrics-v1', ['tenant' => 17])->flexibleMany(
    [10, 20],
    Freshness::seconds(freshFor: 60, staleFor: 300),
    refresh: 'queue',
);
// [10 => ['completed' => 30], 20 => ['completed' => 60]]
```

Create Laravel's queue tables if needed, then run:

```shell
php artisan queue:work database --queue=cache-refresh
```

Only stale refreshes are queued. Cold misses still call the loader immediately. Passing a callback with queue refresh is rejected; closures, requests, authentication objects, and models are never serialized into package jobs. The job contains dataset name, explicit dimensions, item keys, the reader's freshness policy, and a dataset definition fingerprint.

The worker rereads before loading. Duplicate jobs may do no source work when an earlier job already produced fresh data. Redis execution claims are acquired in the worker, not held while a job waits in the queue. Jobs allow three attempts with one-second backoff. Configure worker timeout and the queue's retry interval for the loader's real maximum execution time; the package does not choose those application settings.

Restart workers on deployment. Jobs whose registered definition changed are rejected rather than silently using a different contract. This fingerprint does not migrate cached payloads: change the dataset name, such as `order-metrics-v2`, when the projection or loader meaning changes. Keep a stable definition across the lifetime of any scope object.

No Octane runtime support is claimed by these tests. The package uses scoped scheduler state and explicit dimensions, but applications using long-lived or concurrent HTTP workers need their own runtime verification. Never retain a user-specific scope or request-capturing callback in an application singleton.
