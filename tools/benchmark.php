<?php

declare(strict_types=1);

use GogoSpace\BulkCache\Engine;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Stores\PortableStore;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\LoadContext;
use GogoSpace\BulkCache\Support\Options;
use GogoSpace\BulkCache\Tools\RedisBenchmark;
use GogoSpace\BulkCache\Tools\Verification;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/Verification.php';
require __DIR__.'/Support/RedisBenchmark.php';

final class MeasuredRepository extends Repository
{
    public array $operations = [];

    public function get($key, $default = null): mixed
    {
        $this->operations['get'] = ($this->operations['get'] ?? 0) + 1;

        return parent::get($key, $default);
    }

    public function many(array $keys)
    {
        $this->operations['many'] = ($this->operations['many'] ?? 0) + 1;

        return parent::many($keys);
    }

    public function put($key, $value, $timeToLive = null)
    {
        $this->operations['put'] = ($this->operations['put'] ?? 0) + 1;

        return parent::put($key, $value, $timeToLive);
    }

    public function putMany(array $values, $timeToLive = null)
    {
        $this->operations['putMany'] = ($this->operations['putMany'] ?? 0) + 1;

        return parent::putMany($values, $timeToLive);
    }

    public function add($key, $value, $timeToLive = null)
    {
        $this->operations['add'] = ($this->operations['add'] ?? 0) + 1;

        return parent::add($key, $value, $timeToLive);
    }
}

$configuration = require dirname(__DIR__).'/config/bulk-cache.php';
$configuration['prefix'] = 'benchmark';
$options = new Options($configuration);
// Warm shared framework and codec paths before measuring either variant.
$warmupRepository = new Repository(new ArrayStore(true));
$warmupRepository->putMany(['warmup' => ['value' => 1]], 60);
$warmupRepository->many(['warmup']);
(new Engine(new Clock, new LoadContext))->read(new PortableStore($warmupRepository, 'warmup'), 'warmup', ['key'], Freshness::seconds(60), static fn (): array => ['key' => ['value' => 1]], $options, false);
$results = [];
foreach ([1, 100, 1000] as $count) {
    foreach (['optimized-many', 'bulk-cache-portable'] as $variant) {
        $repository = new MeasuredRepository(new ArrayStore(true));
        $store = new PortableStore($repository, 'benchmark');
        $engine = new Engine(new Clock, new LoadContext);
        $keys = array_map(strval(...), range(1, $count));
        $loaderCalls = 0;
        $loadedItems = 0;
        $loader = function (array $requested) use (&$loaderCalls, &$loadedItems): array {
            $loaderCalls++;
            $loadedItems += count($requested);
            $values = [];
            foreach ($requested as $key) {
                $values[$key] = ['identifier' => $key, 'label' => 'Synthetic item '.$key];
            }

            return $values;
        };
        foreach (['cold', 'warm'] as $phase) {
            $repository->operations = [];
            $loaderCalls = 0;
            $loadedItems = 0;
            memory_reset_peak_usage();
            $memoryBefore = memory_get_usage();
            $started = hrtime(true);
            if ($variant === 'bulk-cache-portable') {
                $values = $engine->read($store, 'dataset', $keys, Freshness::seconds(60), $loader, $options, false)['values'];
            } else {
                $values = [];
                foreach (array_chunk($keys, $configuration['batch_size']) as $chunk) {
                    $cached = $repository->many($chunk);
                    $missing = array_keys(array_filter($cached, static fn ($value): bool => $value === null));
                    if ($missing !== []) {
                        $loaded = $loader(array_map(strval(...), $missing));
                        $repository->putMany($loaded, 60);
                        $cached = array_replace($cached, $loaded);
                    }
                    $values += $cached;
                }
            }
            $elapsed = (hrtime(true) - $started) / 1000000;
            Verification::require(count($values) === $count && $values[1]['identifier'] === '1' && $values[$count]['identifier'] === (string) $count, 'Benchmark result mismatch.');
            Verification::require($loadedItems === ($phase === 'cold' ? $count : 0), 'Benchmark loaded an incorrect number of items.');
            $results[] = ['items' => $count, 'variant' => $variant, 'phase' => $phase, 'cache_method_calls' => $repository->operations, 'cache_method_call_total' => array_sum($repository->operations), 'loader_calls' => $loaderCalls, 'loaded_items' => $loadedItems, 'elapsed_milliseconds' => round($elapsed, 3), 'peak_extra_bytes' => max(0, memory_get_peak_usage() - $memoryBefore)];
        }
    }
}

$report = ['php' => PHP_VERSION, 'operating_system' => PHP_OS_FAMILY, 'store' => 'array with serialization', 'batch_size' => $configuration['batch_size'], 'source' => 'deterministic in-memory synthetic values', 'note' => 'One sample per variant/phase; method counts are not network round trips. Neither variant supplies distributed publication guards. No speedup is inferred.', 'results' => $results];
Verification::writeJson(dirname(__DIR__).'/research/execution/next-beta/logs/benchmark-array.json', $report);
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

$redisReport = (new RedisBenchmark)->run();
Verification::writeJson(dirname(__DIR__).'/research/execution/next-beta/logs/benchmark-redis.json', $redisReport);
echo json_encode($redisReport, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
