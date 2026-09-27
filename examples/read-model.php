<?php

use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Freshness;

$sourceCalls = 0;
$loadMetrics = function (array $keys) use (&$sourceCalls): array {
    $sourceCalls++;
    // Replace this synthetic response with one bounded batch API call or aggregate query.
    // Configure source timeouts separately; cache budgets cannot interrupt blocking I/O.
    $values = [];
    foreach ($keys as $key) {
        $values[$key] = ['completed' => (int) $key * 3, 'unit' => 'orders'];
    }

    return $values;
};

$metrics = BulkCache::scope('example-order-metrics-v1', ['tenant' => 'demo', 'period' => '2026-09']);
$metrics->invalidateScope();
$freshness = Freshness::seconds(freshFor: 60, staleFor: 300);
$first = $metrics->flexibleMany([10, 20], $freshness, $loadMetrics, refresh: 'inline');
$second = $metrics->flexibleMany([10, 20], $freshness, $loadMetrics, refresh: 'inline');

return ['values' => $second, 'same_values' => $first === $second, 'source_calls' => $sourceCalls];
