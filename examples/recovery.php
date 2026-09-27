<?php

declare(strict_types=1);

use GogoSpace\BulkCache\Examples\ExampleEnvironment;
use GogoSpace\BulkCache\Examples\FavoriteApplication;

require_once __DIR__.'/Support/ExampleEnvironment.php';
require_once __DIR__.'/Support/FavoriteApplication.php';

$environment = new ExampleEnvironment($exampleRedisClient ?? null);
try {
    $favorites = new FavoriteApplication($environment->application);
    $favorites->install();
    $before = $favorites->read(1);
    ExampleEnvironment::check($before['all'] === [101], 'The old aggregate must be cached before the source changes.');

    $environment->allowCacheCommands(false);
    $write = $environment->worker('write', ['user' => 1, 'product' => 102]);
    ExampleEnvironment::check($write['saved'] && $write['delivery'] === 'pending', 'A failed invalidation must not turn a successful DB commit into a failed save.');
    ExampleEnvironment::check($write['process'] !== getmypid(), 'The write must run in another process.');
    ExampleEnvironment::check($favorites->pending() === [$write['intent']], 'The DB transaction must retain the invalidation intent.');
    $duringOutage = $favorites->read(1);
    ExampleEnvironment::check($duringOutage['all'] === [102], 'Dirty reads must use the authoritative source even while cache commands fail.');

    $environment->allowCacheCommands(true);
    $oldCache = $favorites->scope(1)->rememberMany(['all'], 300, static function (): never {
        throw new RuntimeException('This assertion expects the preserved old cache entry.');
    });
    ExampleEnvironment::check($oldCache['all']['items'] === [101], 'The outage must preserve stale data, so the test proves recovery rather than an empty-cache restart.');
    ExampleEnvironment::check($favorites->read(1)['all'] === [102], 'Restored connectivity alone must not re-enable dirty cached reads.');

    // A new process discovers pending work from the DB, without an in-memory job or identifier.
    $retry = $environment->worker('deliver');
    ExampleEnvironment::check($retry['process'] !== $write['process'] && $retry['delivered'] === [$write['intent']] && $retry['pending'] === [], 'A fresh process must recover the durable intent.');
    $recovered = $favorites->read(1);
    $duplicate = $environment->worker('deliver', ['intents' => [$write['intent']]]);
    $afterDuplicate = $favorites->read(1);
    ExampleEnvironment::check($recovered === $duringOutage && $afterDuplicate === $duringOutage && $duplicate['pending'] === [], 'Retry and duplicate delivery must never restore old data.');

    return [
        'status' => 'passed',
        'saved_despite_cache_failure' => $write['saved'],
        'durable_intent_recovered_by_fresh_process' => true,
        'stale_entry_survived_outage' => $oldCache['all']['items'],
        'authoritative_during_outage' => $duringOutage['all'],
        'after_retry_and_duplicate' => $afterDuplicate['all'],
        'pending' => $favorites->pending(),
        'client' => $environment->configuration['client'],
    ];
} finally {
    $environment->close();
}
