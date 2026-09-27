<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tools;

use GogoSpace\BulkCache\Contracts\BatchStore;
use GogoSpace\BulkCache\Engine;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Stores\RedisStore;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\LoadContext;
use GogoSpace\BulkCache\Support\Options;
use GogoSpace\BulkCache\Tests\Concurrency\WorkerProcess;
use GogoSpace\BulkCache\Tests\Support\RedisServer;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Predis\Client;

/** One synchronous raw command is one measured request/reply exchange. */
trait MeasuresRedisCommands
{
    public array $commands = [];

    public int $latencyMicroseconds = 1000;

    private function record(string $command): void
    {
        $this->commands[$command] = ($this->commands[$command] ?? 0) + 1;
        // Delay delivery after the real response: leases already run on Redis.
        usleep($this->latencyMicroseconds);
    }
}

final class MeasuredPhpRedis extends \Redis
{
    use MeasuresRedisCommands;

    public function rawcommand(string $command, mixed ...$arguments): mixed
    {
        $result = parent::rawcommand($command, ...$arguments);
        $this->record($command);

        return $result;
    }
}

final class MeasuredPredis extends Client
{
    use MeasuresRedisCommands;

    public function executeRaw(array $arguments, &$error = null)
    {
        $result = parent::executeRaw($arguments, $error);
        $this->record($arguments[0]);

        return $result;
    }
}

final class RedisBenchmark
{
    private RedisServer $server;

    public function run(int $samples = 20): array
    {
        foreach (['redis', 'pcntl', 'posix'] as $extension) {
            Verification::require(extension_loaded($extension), 'Redis measurements require '.$extension.'.');
        }
        $source = $this->sourceIdentity();
        $this->server = new RedisServer;
        try {
            $results = [];
            foreach (['phpredis', 'predis'] as $client) {
                foreach ([10, 100] as $batchSize) {
                    foreach (['many-batch', 'guarded'] as $variant) {
                        foreach (['cold', 'warm', 'mixed', 'overlap'] as $phase) {
                            $observations = [];
                            for ($sample = 0; $sample < $samples; $sample++) {
                                $scope = implode(':', [$client, $batchSize, $variant, $phase, $sample]);
                                $observations[] = $phase === 'overlap'
                                    ? $this->overlap($client, $variant, $scope, $batchSize)
                                    : $this->sample($client, $variant, $scope, $batchSize, $phase);
                            }
                            $results[] = [
                                'client' => $client, 'variant' => $variant, 'phase' => $phase,
                                'items_per_reader' => $batchSize, 'batch_size' => $batchSize,
                                'readers' => $phase === 'overlap' ? 2 : 1, 'samples' => $samples,
                                'p95_milliseconds' => $this->percentile(array_column($observations, 'milliseconds')),
                                'p95_core_milliseconds' => $this->percentile(array_column($observations, 'core_milliseconds')),
                                'p95_store_validation_milliseconds' => $this->percentile(array_column($observations, 'store_validation_milliseconds')),
                                'mean_core_exchanges' => array_sum(array_column($observations, 'core_exchanges')) / $samples,
                                'mean_store_validation_exchanges' => array_sum(array_column($observations, 'store_validation_exchanges')) / $samples,
                                'mean_exchanges' => array_sum(array_column($observations, 'exchanges')) / $samples,
                                'mean_loader_calls' => array_sum(array_column($observations, 'loader_calls')) / $samples,
                                'mean_loaded_items' => array_sum(array_column($observations, 'loaded_items')) / $samples,
                                'observations' => $observations,
                            ];
                        }
                    }
                    $results[] = $this->leaseCost($client, $batchSize, $samples);
                }
            }
            $information = $this->raw($this->client('phpredis'), ['INFO', 'server']);
            preg_match('/redis_version:([^\r\n]+)/', $information, $version);

            return [
                ...$source,
                'emulated_added_round_trip_milliseconds' => 1,
                'php' => PHP_VERSION, 'operating_system' => PHP_OS_FAMILY, 'redis_version' => $version[1] ?? 'unknown',
                'phpredis_version' => phpversion('redis'), 'predis_version' => Client::VERSION,
                'transport' => 'Real isolated Redis on loopback; synchronous raw client commands, no pipelining.',
                'latency_model' => 'A fixed 1 ms delay after every actual raw response emulates added round-trip latency. This is a client response-delivery model, not a production-network measurement. Redis lease time elapses during the delay.',
                'command_accounting' => 'Request commands include a fresh store constructor and its ROLE validation on the already established connection, as Scope does per call. The separate core fields exclude this ROLE for comparison with the pre-batching baseline. Every raw invocation is one synchronous exchange; Lua internal calls add none. Overlap counts sum both readers; elapsed time is their maximum. Fixture setup, acquiring the TCP connection and Laravel container/Scope dispatch are excluded. Standalone lease-acquisition rows measure only claim acquisition.',
                'source' => 'Deterministic synthetic scalar arrays plus 5 ms per loader batch, same work for both variants. Overlap readers share half their keys and start at a process barrier.',
                'baseline' => 'MGET + one batched Lua SET loop, no generation, leases or guarded publication; it is an optimized many+batch cost reference, not an equivalent consistency guarantee.',
                'lease_milliseconds' => 10000, 'results' => $results,
            ];
        } finally {
            $this->server->stop();
        }
    }

    private function sourceIdentity(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['src', 'config'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        foreach (['tools/Support/RedisBenchmark.php', 'tools/benchmark.php', 'tests/Support/RedisServer.php', 'tests/Concurrency/WorkerProcess.php', 'composer.lock'] as $path) {
            $files[$path] = hash_file('sha256', $root.'/'.$path);
        }
        ksort($files);
        $commit = trim(Verification::run(['git', 'rev-parse', 'HEAD'], $root, $root.'/research/execution/next-beta/logs/benchmark-source-commit.txt'));

        return ['source_commit' => $commit, 'source_sha256' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)), 'source_files_sha256' => $files];
    }

    private function client(string $name): MeasuredPhpRedis|MeasuredPredis
    {
        if ($name === 'phpredis') {
            $client = new MeasuredPhpRedis;
            $client->connect('127.0.0.1', $this->server->port, 1);

            return $client;
        }

        return new MeasuredPredis(['host' => '127.0.0.1', 'port' => $this->server->port, 'timeout' => 1, 'read_write_timeout' => 1]);
    }

    private function store(MeasuredPhpRedis|MeasuredPredis $client): RedisStore
    {
        return new RedisStore($client instanceof MeasuredPhpRedis ? new PhpRedisConnection($client) : new PredisConnection($client), 'benchmark:');
    }

    private function options(int $batchSize): Options
    {
        return new Options(array_replace(require dirname(__DIR__, 2).'/config/bulk-cache.php', ['prefix' => 'benchmark', 'batch_size' => $batchSize, 'operation_milliseconds' => 30000]));
    }

    private function sample(string $clientName, string $variant, string $scope, int $batchSize, string $phase, ?WorkerProcess $worker = null, int $offset = 0): array
    {
        $client = $this->client($clientName);
        $store = $variant === 'guarded' ? $this->store($client) : null;
        $engine = new Engine(new Clock, new LoadContext);
        $keys = array_map(strval(...), range(1 + $offset, $batchSize + $offset));
        $loaderCalls = 0;
        $loadedItems = 0;
        $loader = function (array $requested) use (&$loaderCalls, &$loadedItems): array {
            $loaderCalls++;
            $loadedItems += count($requested);
            usleep(5000);

            return array_combine($requested, array_map(fn (string $key): array => ['identifier' => $key, 'label' => 'Synthetic item '.$key], $requested));
        };
        $read = fn (array $requested): array => $store !== null
            ? $engine->read($store, $scope, $requested, Freshness::seconds(60), $loader, $this->options($batchSize), false)['values']
            : $this->baseline($client, $scope, $requested, $loader);
        if ($phase === 'warm' || $phase === 'mixed') {
            $read($phase === 'warm' ? $keys : array_slice($keys, 0, intdiv($batchSize, 2)));
        }
        $client->commands = [];
        $loaderCalls = $loadedItems = 0;
        if ($worker !== null) {
            $worker->send(['event' => 'ready']);
            $worker->receive('start');
        }
        $started = hrtime(true);
        $validationStarted = hrtime(true);
        $validationExchanges = 0;
        if ($store !== null) {
            // Scope constructs a fresh adapter on each request, even for warm reads.
            $store = $this->store($client);
            $validationExchanges = array_sum($client->commands);
        }
        $validationMilliseconds = (hrtime(true) - $validationStarted) / 1000000;
        $coreStarted = hrtime(true);
        $values = $store !== null
            ? $engine->read($store, $scope, $keys, Freshness::seconds(60), $loader, $this->options($batchSize), false)['values']
            : $this->baseline($client, $scope, $keys, $loader);
        $coreMilliseconds = (hrtime(true) - $coreStarted) / 1000000;
        $milliseconds = (hrtime(true) - $started) / 1000000;
        Verification::require(count($values) === count($keys), 'Redis benchmark result count mismatch.');
        foreach ($keys as $key) {
            Verification::require($values[$key]['identifier'] === $key, 'Redis benchmark value mismatch.');
        }
        if ($worker === null) {
            $expectedItems = match ($phase) {
                'warm' => 0, 'mixed' => intdiv($batchSize, 2), default => $batchSize
            };
            Verification::require($loadedItems === $expectedItems, 'Redis benchmark loaded unexpected items.');
            $expectedExchanges = $variant === 'guarded' ? ($phase === 'warm' ? 2 : 6) : ($phase === 'warm' ? 1 : 2);
            Verification::require(array_sum($client->commands) === $expectedExchanges, 'Redis exchange budget changed; inspect the guarded batching path.');
        }

        return ['milliseconds' => round($milliseconds, 3), 'core_milliseconds' => round($coreMilliseconds, 3), 'store_validation_milliseconds' => round($validationMilliseconds, 3), 'commands' => $client->commands, 'exchanges' => array_sum($client->commands), 'core_exchanges' => array_sum($client->commands) - $validationExchanges, 'store_validation_exchanges' => $validationExchanges, 'loader_calls' => $loaderCalls, 'loaded_items' => $loadedItems];
    }

    private function overlap(string $clientName, string $variant, string $scope, int $batchSize): array
    {
        $workers = [];
        foreach ([0, intdiv($batchSize, 2)] as $offset) {
            $workers[] = WorkerProcess::start(function (WorkerProcess $worker) use ($clientName, $variant, $scope, $batchSize, $offset): void {
                $worker->send(['event' => 'result', 'result' => $this->sample($clientName, $variant, $scope, $batchSize, 'overlap', $worker, $offset)]);
            });
        }
        foreach ($workers as $worker) {
            $worker->receive('ready');
        }
        foreach ($workers as $worker) {
            $worker->send(['event' => 'start']);
        }
        $observations = [];
        foreach ($workers as $worker) {
            $observations[] = $worker->receive('result')['result'];
            $worker->join();
        }
        if ($variant === 'guarded') {
            Verification::require(array_sum(array_column($observations, 'loaded_items')) === $batchSize + intdiv($batchSize, 2), 'Guarded overlap repeated source items.');
        }

        return ['milliseconds' => max(array_column($observations, 'milliseconds')), 'core_milliseconds' => max(array_column($observations, 'core_milliseconds')), 'store_validation_milliseconds' => max(array_column($observations, 'store_validation_milliseconds')), 'core_exchanges' => array_sum(array_column($observations, 'core_exchanges')), 'store_validation_exchanges' => array_sum(array_column($observations, 'store_validation_exchanges')), 'commands' => array_column($observations, 'commands'), 'exchanges' => array_sum(array_column($observations, 'exchanges')), 'loader_calls' => array_sum(array_column($observations, 'loader_calls')), 'loaded_items' => array_sum(array_column($observations, 'loaded_items'))];
    }

    private function baseline(MeasuredPhpRedis|MeasuredPredis $client, string $scope, array $keys, callable $loader): array
    {
        $physical = array_map(fn (string $key): string => 'baseline:'.$scope.':'.$key, $keys);
        $cached = $this->raw($client, ['MGET', ...$physical]);
        $values = [];
        $missing = [];
        foreach ($keys as $position => $key) {
            if (! is_string($cached[$position])) {
                $missing[] = $key;
            } else {
                $values[$key] = json_decode($cached[$position], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        if ($missing !== []) {
            $loaded = $loader($missing);
            $physicalMissing = array_map(fn (string $key): string => 'baseline:'.$scope.':'.$key, $missing);
            $payloads = array_map(fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR), array_values($loaded));
            $this->raw($client, ['EVAL', "for position = 1, #KEYS do redis.call('SET', KEYS[position], ARGV[position], 'PX', 60000) end return 1", (string) count($missing), ...$physicalMissing, ...$payloads]);
            $values += $loaded;
        }

        return $values;
    }

    private function leaseCost(string $clientName, int $batchSize, int $samples): array
    {
        $observations = [];
        $inspection = new \Redis;
        $inspection->connect('127.0.0.1', $this->server->port, 1);
        for ($sample = 0; $sample < $samples; $sample++) {
            $client = $this->client($clientName);
            $store = $this->store($client);
            $keys = array_map(strval(...), range(1, $batchSize));
            $scope = 'lease:'.$clientName.':'.$batchSize.':'.$sample;
            $client->commands = [];
            $started = hrtime(true);
            if ($store instanceof BatchStore) {
                $claims = $store->claimMany($scope, $keys, 10000);
            } else {
                $claims = [];
                foreach ($keys as $key) {
                    $claims[$key] = $store->claim($scope, $key, 10000);
                }
            }
            $milliseconds = (hrtime(true) - $started) / 1000000;
            $remaining = $inspection->rawCommand('PTTL', 'benchmark:bulk:v1:'.$scope.':1:lease');
            Verification::require($remaining > 0, 'Claim acquisition exhausted its lease.');
            $observations[] = ['milliseconds' => round($milliseconds, 3), 'exchanges' => array_sum($client->commands), 'first_lease_consumed_milliseconds' => 10000 - $remaining];
            foreach ($claims as $key => $claim) {
                $store->release($scope, (string) $key, $claim);
            }
        }

        return ['client' => $clientName, 'variant' => 'guarded', 'phase' => 'lease-acquisition', 'batch_size' => $batchSize, 'samples' => $samples, 'p95_milliseconds' => $this->percentile(array_column($observations, 'milliseconds')), 'p95_first_lease_consumed_milliseconds' => $this->percentile(array_column($observations, 'first_lease_consumed_milliseconds')), 'mean_exchanges' => array_sum(array_column($observations, 'exchanges')) / $samples, 'observations' => $observations];
    }

    private function raw(MeasuredPhpRedis|MeasuredPredis $client, array $command): mixed
    {
        return $client instanceof MeasuredPhpRedis ? $client->rawCommand(...$command) : $client->executeRaw($command);
    }

    private function percentile(array $values): float
    {
        sort($values);

        return round($values[(int) ceil(count($values) * 0.95) - 1], 3);
    }
}
