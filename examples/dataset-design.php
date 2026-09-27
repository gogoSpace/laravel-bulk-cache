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
    $first = $favorites->read(1);
    $otherUser = $favorites->read(2);
    $empty = $favorites->read(3);
    ExampleEnvironment::check($favorites->read(3) === $empty && $empty === ['all' => [], 'stationery' => [], 'supplies' => []] && $favorites->aggregateLoads[3] === 1, 'An empty aggregate is a cached value, not a missing item.');
    $legacyBefore = $favorites->read(1, 'legacy');
    ExampleEnvironment::check($legacyBefore === $first && $first === ['all' => [101], 'stationery' => [101], 'supplies' => []], 'Warm both implementations before switching.');

    $aliceView = $favorites->view(1);
    $bobView = $favorites->view(2);
    ExampleEnvironment::check($favorites->catalogLoads === 1 && $aliceView[101]['favorite'] && ! $bobView[101]['favorite'] && $bobView[102]['favorite'], 'The catalog is shared and the overlay remains isolated.');
    ExampleEnvironment::check($aliceView[101]['published_on'] === '2026-01-01' && $aliceView[102]['published_on'] === null, 'Eloquent dates must become explicit scalar or null payloads.');
    $otherUserLoads = $favorites->aggregateLoads[2];

    $write = $environment->worker('write', ['user' => 1, 'product' => 102]);
    ExampleEnvironment::check($write['process'] !== getmypid() && $write['saved'] && $write['delivery'] === 'complete' && $write['model_events'] === 0, 'An external bulk writer must deliver invalidation without relying on model events.');
    $after = $favorites->read(1);
    ExampleEnvironment::check($after === ['all' => [102], 'stationery' => [], 'supplies' => [102]], 'The exact user scope must invalidate every dependent filter, including newly empty variants.');
    ExampleEnvironment::check($favorites->read(2) === $otherUser && $favorites->aggregateLoads[2] === $otherUserLoads, 'Another user must retain both their values and their warm cache.');
    $legacyAfter = $favorites->read(1, 'legacy');
    ExampleEnvironment::check($legacyAfter === $after && $favorites->read(1, 'bulk') === $after, 'Legacy/bulk/legacy switching must use the source revision and never resurrect the old legacy entry.');
    $oldLegacyEntry = $environment->application['cache']->store('file')->get('example:legacy-favorites:1:1');
    ExampleEnvironment::check($oldLegacyEntry['all']['items'] === [101], 'Old legacy data must remain present to prove that rollback no longer addresses it.');

    $environment->worker('rename-product', ['product' => 101, 'name' => 'Renamed notebook']);
    $renamedView = $favorites->view(2);
    ExampleEnvironment::check($renamedView[101]['name'] === 'Renamed notebook' && $renamedView[102]['favorite'] && $favorites->aggregateLoads[2] === $otherUserLoads && $favorites->catalogLoads === 2, 'A shared display-field change must refresh the catalog while retaining the personal aggregate.');

    return [
        'status' => 'passed',
        'empty_aggregate_cached' => $empty,
        'external_bulk_write_model_events' => $write['model_events'],
        'all_variants_after_write' => $after,
        'other_user_stayed_warm' => true,
        'shared_catalog_loads' => $favorites->catalogLoads,
        'eloquent_date' => $aliceView[101]['published_on'],
        'legacy_after_switch_back' => $legacyAfter['all'],
        'old_legacy_entry' => $oldLegacyEntry['all']['items'],
        'client' => $environment->configuration['client'],
    ];
} finally {
    $environment->close();
}
