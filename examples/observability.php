<?php

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Events\CacheEvent;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;

$configuration = app('config');
$original = $configuration->get('bulk-cache');
$events = [];
$listener = function (CacheEvent $event) use (&$events): void {
    $events[] = $event;
};
app('events')->listen(CacheEvent::class, $listener);

try {
    $configuration->set('bulk-cache.datasets.observed-preferences', [
        'driver' => 'portable',
        'store' => 'array',
        'dimensions' => ['user' => 'int'],
        'events' => true,
    ]);
    $manager = app(BulkCacheManager::class);
    try {
        $manager->scope('observed-preferences');
        throw new RuntimeException('A required user identity was omitted.');
    } catch (ConfigurationException) {
        // The invalid scope fails before any source or cache work.
    }
    $scope = $manager->scope('observed-preferences', ['user' => 42]);
    $scope->invalidateScope();
    $calls = 0;
    $loader = function (array $keys) use (&$calls): array {
        $calls++;

        return array_fill_keys($keys, ['theme' => 'dark']);
    };
    $first = $scope->rememberMany(['all'], 60, $loader);
    $second = $scope->rememberMany(['all'], 60, $loader);
    $operations = array_values(array_unique(array_map(fn (CacheEvent $event): string => $event->operation, $events)));
    if ($first !== $second || $calls !== 1 || ! in_array('loader', $operations, true) || ! in_array('cache.read', $operations, true)) {
        throw new RuntimeException('The observation example did not produce the expected miss and hit.');
    }
    foreach ($events as $event) {
        if ($event->dataset !== 'observed-preferences' || array_keys(get_object_vars($event)) !== ['dataset', 'operation', 'status', 'count', 'milliseconds']) {
            throw new RuntimeException('Unexpected observation fields.');
        }
    }

    return ['status' => 'passed', 'source_calls' => $calls, 'event_count' => count($events), 'operations' => $operations];
} finally {
    $configuration->set('bulk-cache', $original);
    // This example owns its isolated dispatcher. Register production listeners once at boot.
    app('events')->forget(CacheEvent::class);
}
