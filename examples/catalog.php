<?php

use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Missing;

// Synthetic source data. A real loader should query all requested keys at once.
$products = ['101' => ['name' => 'Notebook'], '102' => ['name' => 'Pencil']];
$favorites = ['alice' => ['101'], 'bob' => ['102']];
$publicLoads = 0;
$public = BulkCache::scope('example-public-products-v1', ['tenant' => 'demo', 'locale' => 'en']);
// Reset the example scope for repeatable runs. Remove this from normal application reads.
$public->invalidateScope();
$loadProducts = function (array $keys) use ($products, &$publicLoads): array {
    $publicLoads++;
    $values = [];
    foreach ($keys as $key) {
        $values[$key] = $products[$key] ?? Missing::Value;
    }

    return $values;
};

$views = [];
foreach (['alice', 'bob', 'guest'] as $subject) {
    // In an application, authorize first and copy the subject into explicit dimensions.
    $overlay = BulkCache::scope('example-favorites-v1', ['tenant' => 'demo', 'user' => $subject]);
    // Reset each example user's scope for repeatable runs. Remove this from normal application reads.
    $overlay->invalidateScope();
    $flags = $overlay->rememberMany([101, 102], 60, function (array $keys) use ($subject, $favorites): array {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = in_array($key, $favorites[$subject] ?? [], true);
        }

        return $values;
    });
    $catalog = $public->rememberMany([101, 102], 60, $loadProducts);
    foreach ($catalog as $key => $product) {
        $views[$subject][$key] = $product + ['favorite' => $flags[$key]];
    }
}

return ['views' => $views, 'public_loader_calls' => $publicLoads];
