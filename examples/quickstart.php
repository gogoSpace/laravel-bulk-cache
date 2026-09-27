<?php

use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Missing;

$source = ['101' => ['name' => 'Notebook'], '102' => ['name' => 'Pencil']];
$loaderCalls = 0;
$loader = function (array $keys) use (&$source, &$loaderCalls): array {
    $loaderCalls++;
    $values = [];
    foreach ($keys as $key) {
        $values[$key] = $source[$key] ?? Missing::Value;
    }

    return $values;
};

$catalog = BulkCache::scope('quickstart-catalog-v1', ['locale' => 'en']);
$catalog->invalidateScope();
$first = $catalog->rememberMany([101, 102, 999], 60, $loader);
$second = $catalog->rememberMany([101, 102, 999], 60, $loader);

$source['101'] = ['name' => 'Updated notebook'];
$catalog->invalidateMany([101]);
$updated = $catalog->rememberMany([101, 102], 60, $loader);

return [
    'same_values' => $first === $second,
    'missing' => $second[999] === Missing::Value,
    'updated_name' => $updated[101]['name'],
    'loader_calls' => $loaderCalls,
];
