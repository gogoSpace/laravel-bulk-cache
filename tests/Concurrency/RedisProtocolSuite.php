<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Concurrency;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Engine;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Exceptions\TimeoutException;
use GogoSpace\BulkCache\RefreshScheduler;
use GogoSpace\BulkCache\Stores\RedisStore;
use GogoSpace\BulkCache\Support\Claim;
use GogoSpace\BulkCache\Support\Identity;
use GogoSpace\BulkCache\Support\LoadContext;
use GogoSpace\BulkCache\Support\Options;
use GogoSpace\BulkCache\Tests\Support\RedisServer;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\RedisManager;
use Predis\Client;
use RuntimeException;

final class RedisProtocolSuite
{
    private readonly string $prefix;

    public function __construct(private readonly RedisServer $server, private readonly string $client)
    {
        $this->prefix = 'suite:'.$client.':'.bin2hex(random_bytes(6)).':';
    }

    public function run(): void
    {
        foreach ([
            'rawPayloadsAndIsolation', 'twentyConcurrentReaders', 'overlappingSets',
            'reverseOverlap', 'cyclicOverlap', 'rereadAfterClaim', 'expiryAndDelayedRelease',
            'invalidationDuringLoad', 'generationLoss', 'payloadLoss', 'workerDeath',
            'ambiguousOperations', 'writeFailures', 'partialPublication', 'scriptCacheReset',
            'publicApiConcurrentReaders', 'publicApiOverlap', 'publicApiRejectedResults', 'publicApiBoundedWaiting',
            'clientOptions', 'unsupportedTopologies', 'deniedScript', 'serverOutage',
        ] as $scenario) {
            $this->{$scenario}();
            fwrite(STDOUT, $this->client.' '.$scenario." passed\n");
        }
    }

    private function rawPayloadsAndIsolation(): void
    {
        $connection = $this->connection();
        $client = $connection->client();

        if ($client instanceof \Redis) {
            $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $client->setOption(\Redis::OPT_PREFIX, 'connection:');
            $serializer = $client->getOption(\Redis::OPT_SERIALIZER);
        } else {
            $connection = new PredisConnection(new Client([
                'host' => '127.0.0.1', 'port' => $this->server->port, 'timeout' => 1,
                'read_write_timeout' => 1,
            ], ['prefix' => 'connection:']));
        }

        $store = new RedisStore($connection, $this->prefix.'first:');
        $otherApplication = new RedisStore($connection, $this->prefix.'second:');
        $payload = "\0raw:".serialize([null, false, 0, '', [], ['nested' => true]]);
        $claim = $this->owned($store, 'payloads', 'item');
        $otherClaim = $this->owned($otherApplication, 'payloads', 'item');
        $this->assert($store->publish('payloads', 'item', $claim, $payload, 10_000), 'Payload publication failed.');
        $this->assert($otherApplication->publish('payloads', 'item', $otherClaim, 'other', 10_000), 'Application identities interfered.');
        $this->same(['item' => $payload, 'absent' => null], $store->readMany('payloads', ['item', 'absent']));
        $store->invalidateScope('payloads');
        $this->same(['item' => null], $store->readMany('payloads', ['item']));
        $this->same(['item' => 'other'], $otherApplication->readMany('payloads', ['item']));
        $this->assert($this->raw(['EXISTS', 'connection:'.$this->prefix.'second:bulk:v1:payloads:item:value']) === 1, 'The configured client prefix was ignored.');

        if ($client instanceof \Redis) {
            $this->same($serializer, $client->getOption(\Redis::OPT_SERIALIZER));
            $this->same('connection:', $client->getOption(\Redis::OPT_PREFIX));
        }
    }

    private function twentyConcurrentReaders(): void
    {
        $workers = [];

        for ($number = 0; $number < 20; $number++) {
            $workers[] = WorkerProcess::start(function (WorkerProcess $worker): void {
                $store = $this->store();
                $worker->send(['event' => 'ready']);
                $worker->receive('start');
                $deadline = hrtime(true) + 5_000_000_000;
                $remaining = ['A', 'B', 'C'];

                while ($remaining !== [] && hrtime(true) < $deadline) {
                    $values = $store->readMany('identical', $remaining);
                    $remaining = array_keys(array_filter($values, fn ($value) => $value === null));
                    $claims = [];

                    foreach ($remaining as $key) {
                        $claim = $store->claim('identical', $key, 2000);

                        if ($claim !== null) {
                            $claims[$key] = $claim;
                        }
                    }

                    foreach ($claims as $key => $claim) {
                        try {
                            if ($store->readMany('identical', [$key])[$key] === null) {
                                $this->raw(['INCR', $this->prefix.'loads:'.$key]);
                                usleep(20_000);
                                $this->assert($store->publish('identical', $key, $claim, 'value-'.$key, 10_000), 'Unexpected duplicate producer or expired lease.');
                            }
                        } finally {
                            $store->release('identical', $key, $claim);
                        }
                    }

                    usleep(1000);
                }

                $this->same([], $remaining);
                $this->same(['A' => 'value-A', 'B' => 'value-B', 'C' => 'value-C'], $store->readMany('identical', ['A', 'B', 'C']));
            });
        }

        foreach ($workers as $worker) {
            $worker->receive('ready');
        }

        foreach ($workers as $worker) {
            $worker->send(['event' => 'start']);
        }

        foreach ($workers as $worker) {
            $worker->join();
        }

        foreach (['A', 'B', 'C'] as $key) {
            $this->same('1', $this->raw(['GET', $this->prefix.'loads:'.$key]));
        }
    }

    private function overlappingSets(): void
    {
        $first = WorkerProcess::start(function (WorkerProcess $worker): void {
            $store = $this->store();
            $claims = ['A' => $this->owned($store, 'overlap', 'A'), 'B' => $this->owned($store, 'overlap', 'B')];
            $worker->send(['event' => 'owns-A-B']);
            $worker->receive('publish');

            foreach ($claims as $key => $claim) {
                $this->assert($store->publish('overlap', $key, $claim, $key, 10_000), 'First overlap producer was rejected.');
            }
        });
        $first->receive('owns-A-B');
        $second = WorkerProcess::start(function (WorkerProcess $worker): void {
            $store = $this->store();
            $this->same(null, $store->claim('overlap', 'B', 1000));
            $claim = $this->owned($store, 'overlap', 'C');
            $this->assert($store->publish('overlap', 'C', $claim, 'C', 10_000), 'Independent C did not progress.');
            $worker->send(['event' => 'published-C-before-waiting']);
            $worker->receive('read-B');
            $this->same(['B' => 'B', 'C' => 'C'], $store->readMany('overlap', ['B', 'C']));
        });
        $second->receive('published-C-before-waiting');
        $this->same(['B' => null, 'C' => 'C'], $this->store()->readMany('overlap', ['B', 'C']));
        $this->same(0, $this->raw(['EXISTS', $this->physical('overlap', 'C', 'lease')]));
        $first->send(['event' => 'publish']);
        $first->join();
        $second->send(['event' => 'read-B']);
        $second->join();
    }

    private function reverseOverlap(): void
    {
        $first = WorkerProcess::start(function (WorkerProcess $worker): void {
            $store = $this->store();
            $claim = $this->owned($store, 'reverse', 'A');
            $worker->send(['event' => 'owns-A']);
            $worker->receive('claim-B');
            $this->same(null, $store->claim('reverse', 'B', 1000));
            $this->assert($store->publish('reverse', 'A', $claim, 'A', 10_000), 'A must complete before waiting for B.');
            $worker->send(['event' => 'published-A']);
        });
        $first->receive('owns-A');
        $store = $this->store();
        $claims = ['B' => $this->owned($store, 'reverse', 'B'), 'C' => $this->owned($store, 'reverse', 'C')];
        $first->send(['event' => 'claim-B']);
        $first->receive('published-A');

        foreach ($claims as $key => $claim) {
            $this->assert($store->publish('reverse', $key, $claim, $key, 10_000), 'Reverse overlap failed.');
        }

        $first->join();
        $this->same(['A' => 'A', 'B' => 'B', 'C' => 'C'], $store->readMany('reverse', ['A', 'B', 'C']));
    }

    private function cyclicOverlap(): void
    {
        $workers = [];

        foreach (['A' => 'B', 'B' => 'C', 'C' => 'A'] as $ownedKey => $busyKey) {
            $workers[] = WorkerProcess::start(function (WorkerProcess $worker) use ($ownedKey, $busyKey): void {
                $store = $this->store();
                $claim = $this->owned($store, 'cycle', $ownedKey);
                $worker->send(['event' => 'owns-one']);
                $worker->receive('try-other');
                $this->same(null, $store->claim('cycle', $busyKey, 1000));
                $worker->send(['event' => 'other-busy']);
                $worker->receive('publish-own');
                $this->assert($store->publish('cycle', $ownedKey, $claim, $ownedKey, 10_000), 'Cycle did not make progress.');
            });
        }

        foreach ($workers as $worker) {
            $worker->receive('owns-one');
        }

        foreach ($workers as $worker) {
            $worker->send(['event' => 'try-other']);
        }

        foreach ($workers as $worker) {
            $worker->receive('other-busy');
        }

        foreach ($workers as $worker) {
            $worker->send(['event' => 'publish-own']);
        }

        foreach ($workers as $worker) {
            $worker->join();
        }

        $this->same(['A' => 'A', 'B' => 'B', 'C' => 'C'], $this->store()->readMany('cycle', ['A', 'B', 'C']));
    }

    private function rereadAfterClaim(): void
    {
        $worker = WorkerProcess::start(function (WorkerProcess $worker): void {
            $store = $this->store();
            $this->same(['item' => null], $store->readMany('reread', ['item']));
            $worker->send(['event' => 'read-miss']);
            $worker->receive('claim');
            $claim = $this->owned($store, 'reread', 'item');
            $this->same(['item' => 'winner'], $store->readMany('reread', ['item']));
            $store->release('reread', 'item', $claim);
        });
        $worker->receive('read-miss');
        $store = $this->store();
        $claim = $this->owned($store, 'reread', 'item');
        $this->assert($store->publish('reread', 'item', $claim, 'winner', 10_000), 'Reread fixture publish failed.');
        $worker->send(['event' => 'claim']);
        $worker->join();
    }

    private function expiryAndDelayedRelease(): void
    {
        $worker = WorkerProcess::start(function (WorkerProcess $worker): void {
            $store = $this->store();
            $claim = $this->owned($store, 'expiry', 'item', 100);
            $worker->send(['event' => 'claimed']);
            $worker->receive('after-expiry');
            $this->same(false, $store->publish('expiry', 'item', $claim, 'expired', 10_000));
            $worker->send(['event' => 'expiry-rejected']);
            $worker->receive('release-old');
            $store->release('expiry', 'item', $claim);
            $this->same(false, $store->publish('expiry', 'item', $claim, 'old', 10_000));
        });
        $worker->receive('claimed');
        $this->awaitLeaseExpiry('expiry', 'item');
        $worker->send(['event' => 'after-expiry']);
        $worker->receive('expiry-rejected');
        $store = $this->store();
        $successor = $this->owned($store, 'expiry', 'item');
        $worker->send(['event' => 'release-old']);
        $worker->join();
        $this->assert($store->publish('expiry', 'item', $successor, 'new', 10_000), 'Delayed release deleted the successor lease.');
        $this->same(['item' => 'new'], $store->readMany('expiry', ['item']));
    }

    private function invalidationDuringLoad(): void
    {
        foreach (['item-invalidation', 'scope-invalidation'] as $scope) {
            $worker = WorkerProcess::start(function (WorkerProcess $worker) use ($scope): void {
                $store = $this->store();
                $claim = $this->owned($store, $scope, 'item');
                $worker->send(['event' => 'loaded-old']);
                $worker->receive('publish-old');
                $this->same(false, $store->publish($scope, 'item', $claim, 'old', 10_000));
                $store->release($scope, 'item', $claim);
                $this->same(['item' => 'new'], $store->readMany($scope, ['item']));
            });
            $worker->receive('loaded-old');
            $store = $this->store();

            if ($scope === 'item-invalidation') {
                $store->invalidateMany($scope, ['item']);
            } else {
                $store->invalidateScope($scope);
            }

            $claim = $this->owned($store, $scope, 'item');
            $this->assert($store->publish($scope, 'item', $claim, 'new', 10_000), 'Invalidation did not release the old producer.');
            $worker->send(['event' => 'publish-old']);
            $worker->join();
        }
    }

    private function generationLoss(): void
    {
        $store = $this->store();
        $oldClaim = $this->owned($store, 'generation-loss', 'item');
        $this->raw(['DEL', $this->prefix.'bulk:v1:generation-loss:generation']);
        $this->same(false, $store->publish('generation-loss', 'item', $oldClaim, 'old', 10_000));
        $newClaim = $this->owned($store, 'generation-loss', 'item');
        $this->assert($oldClaim->generation !== $newClaim->generation, 'Missing metadata reused a previous incarnation.');
        $store->release('generation-loss', 'item', $oldClaim);
        $this->same(false, $store->publish('generation-loss', 'item', $oldClaim, 'old', 10_000));
        $this->assert($store->publish('generation-loss', 'item', $newClaim, 'new', 10_000), 'New incarnation failed.');
        $oldClaim = $this->owned($store, 'owner-loss', 'item');
        $this->raw(['DEL', $this->physical('owner-loss', 'item', 'lease')]);
        $this->same(false, $store->publish('owner-loss', 'item', $oldClaim, 'unowned', 10_000));
    }

    private function payloadLoss(): void
    {
        $store = $this->store();
        $claim = $this->owned($store, 'payload-loss', 'item');
        $this->assert($store->publish('payload-loss', 'item', $claim, 'previous', 10_000), 'Payload fixture failed.');
        $nextClaim = $this->owned($store, 'payload-loss', 'item');
        $this->raw(['DEL', $this->physical('payload-loss', 'item', 'value')]);
        $this->assert($store->publish('payload-loss', 'item', $nextClaim, 'whole-envelope', 10_000), 'Payload eviction incorrectly revoked a live owner.');
        $this->same(['item' => 'whole-envelope'], $store->readMany('payload-loss', ['item']));
    }

    private function workerDeath(): void
    {
        $worker = WorkerProcess::start(function (WorkerProcess $worker): void {
            $this->owned($this->store(), 'worker-death', 'item', 100);
            $worker->send(['event' => 'claimed']);
            $worker->receive('never');
        });
        $worker->receive('claimed');
        $worker->kill();
        $this->awaitLeaseExpiry('worker-death', 'item');
        $store = $this->store();
        $claim = $this->owned($store, 'worker-death', 'item');
        $this->assert($store->publish('worker-death', 'item', $claim, 'recovered', 10_000), 'Dead worker permanently blocked the key.');
    }

    private function ambiguousOperations(): void
    {
        $connection = $this->connection(true);
        $client = $connection->client();
        $store = new RedisStore($connection, $this->prefix);
        $client->loseNextReply = true;
        $this->throwsStoreFailure(fn () => $store->claim('lost-claim', 'item', 100));
        $this->same(null, $this->store()->claim('lost-claim', 'item', 100));
        $this->awaitLeaseExpiry('lost-claim', 'item');
        $claim = $this->owned($store, 'lost-publication', 'item');
        $before = $client->mutationCalls;
        $client->loseNextReply = true;
        $this->assert($store->publish('lost-publication', 'item', $claim, 'confirmed', 10_000), 'A committed publication was not confirmed after reply loss.');
        $this->same($before + 1, $client->mutationCalls);
        $this->same(['item' => 'confirmed'], $store->readMany('lost-publication', ['item']));
    }

    private function writeFailures(): void
    {
        $store = $this->store();
        $claim = $this->owned($store, 'out-of-memory', 'item');
        $this->raw(['CONFIG', 'SET', 'maxmemory', '1']);

        try {
            $this->throwsStoreFailure(fn () => $store->publish('out-of-memory', 'item', $claim, 'must-not-appear', 10_000));
        } finally {
            $this->raw(['CONFIG', 'SET', 'maxmemory', '0']);
        }

        $this->same(['item' => null], $store->readMany('out-of-memory', ['item']));
        $this->assert($store->publish('out-of-memory', 'item', $claim, 'after-recovery', 10_000), 'Write rejection corrupted the owner.');
        $claim = $this->owned($store, 'wrong-type', 'item');
        $this->raw(['LPUSH', $this->physical('wrong-type', 'item', 'value'), 'corrupt']);
        $this->throwsStoreFailure(fn () => $store->publish('wrong-type', 'item', $claim, 'must-not-appear', 10_000));
        $this->same(['corrupt'], $this->raw(['LRANGE', $this->physical('wrong-type', 'item', 'value'), '0', '-1']));
        $this->same($claim->generation.':'.$claim->owner, $this->raw(['GET', $this->physical('wrong-type', 'item', 'lease')]));
        $this->throwsStoreFailure(fn () => $store->readMany('wrong-type', ['item']));
        $store->invalidateMany('wrong-type', ['item']);
        $this->same(['item' => null], $store->readMany('wrong-type', ['item']));
    }

    private function partialPublication(): void
    {
        $store = $this->store();
        $firstClaim = $this->owned($store, 'partial', 'A');
        $secondClaim = $this->owned($store, 'partial', 'B');
        $this->raw(['LPUSH', $this->physical('partial', 'B', 'value'), 'corrupt']);
        $this->assert($store->publish('partial', 'A', $firstClaim, 'accepted', 10_000), 'Independent item publication failed.');
        $this->throwsStoreFailure(fn () => $store->publish('partial', 'B', $secondClaim, 'failed', 10_000));
        $this->same(['A' => 'accepted'], $store->readMany('partial', ['A']));
        $store->invalidateMany('partial', ['B']);
        $this->same(['B' => null], $store->readMany('partial', ['B']));
    }

    private function scriptCacheReset(): void
    {
        $store = $this->store();
        $claim = $this->owned($store, 'scripts', 'item');
        $this->raw(['SCRIPT', 'FLUSH']);
        $this->assert($store->publish('scripts', 'item', $claim, 'available', 10_000), 'Script cache reset broke publication.');
    }

    private function publicApiConcurrentReaders(): void
    {
        $workers = [];

        for ($number = 0; $number < 20; $number++) {
            $workers[] = WorkerProcess::start(function (WorkerProcess $worker): void {
                $scope = $this->manager()->scope('api-identical', ['tenant' => 7]);
                $worker->send(['event' => 'ready']);
                $worker->receive('start');
                $values = $scope->rememberMany(['A', 'B', 'C'], 30, function (array $keys): array {
                    $values = [];
                    foreach ($keys as $key) {
                        $this->raw(['INCR', $this->prefix.'api-loads:'.$key]);
                        $values[$key] = 'loaded-'.$key;
                    }
                    usleep(20_000);

                    return $values;
                });
                $this->same(['A' => 'loaded-A', 'B' => 'loaded-B', 'C' => 'loaded-C'], $values);
            });
        }

        foreach ($workers as $worker) {
            $worker->receive('ready');
        }
        foreach ($workers as $worker) {
            $worker->send(['event' => 'start']);
        }
        foreach ($workers as $worker) {
            $worker->join();
        }
        foreach (['A', 'B', 'C'] as $key) {
            $this->same('1', $this->raw(['GET', $this->prefix.'api-loads:'.$key]));
        }
    }

    private function publicApiOverlap(): void
    {
        $first = WorkerProcess::start(function (WorkerProcess $worker): void {
            $values = $this->manager()->scope('api-overlap')->rememberMany(['A', 'B'], 30, function (array $keys) use ($worker): array {
                $this->same(['A', 'B'], $keys);
                $worker->send(['event' => 'loading-A-B']);
                $worker->receive('complete');

                return ['A' => 'A', 'B' => 'B'];
            });
            $this->same(['A' => 'A', 'B' => 'B'], $values);
        });
        $first->receive('loading-A-B');
        $second = WorkerProcess::start(function (WorkerProcess $worker): void {
            $values = $this->manager()->scope('api-overlap')->rememberMany(['B', 'C'], 30, function (array $keys) use ($worker): array {
                $this->same(['C'], $keys);
                $worker->send(['event' => 'loading-C']);

                return ['C' => 'C'];
            });
            $this->same(['B' => 'B', 'C' => 'C'], $values);
        });
        $second->receive('loading-C');
        $store = $this->manager()->store(new Options($this->configuration()));
        $deadline = hrtime(true) + 2_000_000_000;
        while ($store->readMany(Identity::scope('api-overlap', []), [hash('sha256', 'C')])[hash('sha256', 'C')] === null) {
            $this->assert(hrtime(true) < $deadline, 'The engine waited for B while still holding C.');
            usleep(1000);
        }
        $first->send(['event' => 'complete']);
        $first->join();
        $second->join();
    }

    private function publicApiRejectedResults(): void
    {
        foreach (['item', 'scope', 'expiry'] as $reason) {
            $dataset = 'api-rejected-'.$reason;
            $worker = WorkerProcess::start(function (WorkerProcess $worker) use ($dataset): void {
                $values = $this->manager(['lease_milliseconds' => 100])->scope($dataset)->rememberMany(['item'], 30, function (array $keys) use ($worker): array {
                    $worker->send(['event' => 'loaded-old']);
                    $worker->receive('return-old');

                    return ['item' => 'old'];
                });
                $this->same(['item' => 'new'], $values);
            });
            $worker->receive('loaded-old');
            $scope = $this->manager()->scope($dataset);
            if ($reason === 'item') {
                $scope->invalidateMany(['item']);
            } elseif ($reason === 'scope') {
                $scope->invalidateScope();
            } else {
                $leaseKey = 'bc:v1:'.hash('sha256', $this->prefix.':redis').'bulk:v1:'.Identity::scope($dataset, []).':'.hash('sha256', 'item').':lease';
                $deadline = hrtime(true) + 2_000_000_000;
                while ($this->raw(['PTTL', $leaseKey]) !== -2) {
                    $this->assert(hrtime(true) < $deadline, 'API owner did not expire.');
                    usleep(1000);
                }
            }
            $this->same(['item' => 'new'], $scope->rememberMany(['item'], 30, fn () => ['item' => 'new']));
            $worker->send(['event' => 'return-old']);
            $worker->join();
        }
    }

    private function publicApiBoundedWaiting(): void
    {
        $manager = $this->manager(['wait_milliseconds' => 80]);
        $store = $manager->store(new Options($this->configuration(['wait_milliseconds' => 80])));
        $identity = Identity::scope('api-timeout', []);
        $token = hash('sha256', 'item');
        $claim = $this->owned($store, $identity, $token);
        $started = hrtime(true);
        $loaderCalls = 0;
        try {
            $manager->scope('api-timeout')->rememberMany(['item'], 30, function () use (&$loaderCalls): array {
                $loaderCalls++;

                return ['item' => 'unprotected'];
            });
            throw new RuntimeException('Contention did not time out.');
        } catch (TimeoutException) {
            $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
            $this->assert($elapsedMilliseconds >= 70 && $elapsedMilliseconds < 1500, 'The waiting budget is not bounded.');
            $this->same(0, $loaderCalls);
        } finally {
            $store->release($identity, $token, $claim);
        }
    }

    private function clientOptions(): void
    {
        if ($this->client !== 'phpredis') {
            $this->same(['missing' => null], $this->store()->readMany('client-options', ['missing']));

            return;
        }
        foreach ([\Redis::SERIALIZER_NONE, \Redis::SERIALIZER_PHP, \Redis::SERIALIZER_JSON] as $serializer) {
            $connection = $this->connection();
            $client = $connection->client();
            $client->setOption(\Redis::OPT_SERIALIZER, $serializer);
            $client->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_NONE);
            $store = new RedisStore($connection, $this->prefix);
            $key = 'serializer-'.$serializer;
            $claim = $this->owned($store, 'client-options', $key);
            $payload = serialize(['null' => null, 'false' => false, 'binary' => "\0\xff"]);
            $this->assert($store->publish('client-options', $key, $claim, $payload, 10_000), 'Serializer options changed the raw protocol.');
            $this->same([$key => $payload], $store->readMany('client-options', [$key]));
            $this->same($serializer, $client->getOption(\Redis::OPT_SERIALIZER));
            $this->same(\Redis::COMPRESSION_NONE, $client->getOption(\Redis::OPT_COMPRESSION));
        }
    }

    private function unsupportedTopologies(): void
    {
        $replica = new RedisServer($this->server->port);
        try {
            $suite = new self($replica, $this->client);
            $this->throwsConfigurationFailure(fn () => $suite->store());
        } finally {
            $replica->stop();
        }
        if ($this->client === 'predis') {
            $cluster = new Client([
                ['host' => '127.0.0.1', 'port' => $this->server->port],
            ], ['cluster' => 'redis']);
            $this->throwsConfigurationFailure(fn () => new RedisStore(new PredisConnection($cluster), $this->prefix));
        }
    }

    private function deniedScript(): void
    {
        $username = 'no-eval-'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $this->raw(['ACL', 'SETUSER', $username, 'on', '>'.$password, '~*', '+role', '+get']);
        try {
            $connection = $this->connection();
            $client = $connection->client();
            if ($client instanceof \Redis) {
                $client->auth([$username, $password]);
            } else {
                $client->executeRaw(['AUTH', $username, $password]);
            }
            $store = new RedisStore($connection, $this->prefix);
            $this->throwsStoreFailure(fn () => $store->readMany('acl', ['item']));
            $this->throwsStoreFailure(fn () => $store->claim('acl', 'item', 1000));
            $this->same(0, $this->raw(['EXISTS', $this->physical('acl', 'item', 'lease')]));
        } finally {
            $this->raw(['ACL', 'DELUSER', $username]);
        }
    }

    private function serverOutage(): void
    {
        $server = new RedisServer;
        $suite = new self($server, $this->client);
        $store = $suite->store();
        $server->stop();
        $this->throwsStoreFailure(fn () => $store->readMany('outage', ['item']));
        $this->throwsStoreFailure(fn () => $store->claim('outage', 'item', 1000));
    }

    private function manager(array $overrides = []): BulkCacheManager
    {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance('config', new Repository(['bulk-cache' => $this->configuration($overrides)]));
        $redis = new RedisManager($container, $this->client, ['default' => [
            'host' => '127.0.0.1', 'port' => $this->server->port, 'timeout' => 1, 'read_timeout' => 1, 'read_write_timeout' => 1,
        ]]);
        $container->instance('redis', $redis);
        $container->instance(Factory::class, $redis);
        $container->singleton(LoadContext::class);
        $container->singleton(Engine::class);
        $container->singleton(RefreshScheduler::class);

        return new BulkCacheManager($container);
    }

    private function configuration(array $overrides = []): array
    {
        return array_replace(require dirname(__DIR__, 2).'/config/bulk-cache.php', [
            'driver' => 'redis', 'prefix' => $this->prefix, 'connection' => 'default',
        ], $overrides);
    }

    private function connection(bool $loseReplies = false): Connection
    {
        if ($this->client === 'phpredis') {
            $client = $loseReplies ? new LostReplyPhpRedis : new \Redis;
            $client->connect('127.0.0.1', $this->server->port, 1);
            $client->setOption(\Redis::OPT_READ_TIMEOUT, 1);

            return new PhpRedisConnection($client);
        }

        $configuration = ['host' => '127.0.0.1', 'port' => $this->server->port, 'timeout' => 1, 'read_write_timeout' => 1];
        $client = $loseReplies ? new LostReplyPredis($configuration) : new Client($configuration);

        return new PredisConnection($client);
    }

    private function store(): RedisStore
    {
        return new RedisStore($this->connection(), $this->prefix);
    }

    private function owned(RedisStore $store, string $scope, string $key, int $leaseMilliseconds = 5000): Claim
    {
        $claim = $store->claim($scope, $key, $leaseMilliseconds);
        $this->assert($claim instanceof Claim, 'Expected ownership for '.$scope.'/'.$key.'.');

        return $claim;
    }

    /** @param list<string> $command */
    private function raw(array $command): mixed
    {
        $connection = $this->connection();
        $client = $connection->client();

        return $client instanceof \Redis ? $client->rawCommand(...$command) : $client->executeRaw($command);
    }

    private function physical(string $scope, string $key, string $part): string
    {
        return $this->prefix.'bulk:v1:'.$scope.':'.$key.':'.$part;
    }

    private function awaitLeaseExpiry(string $scope, string $key): void
    {
        $deadline = hrtime(true) + 2_000_000_000;

        while ($this->raw(['PTTL', $this->physical($scope, $key, 'lease')]) !== -2) {
            if (hrtime(true) > $deadline) {
                throw new RuntimeException('Lease expiry exceeded the test deadline.');
            }

            usleep(1000);
        }
    }

    private function throwsStoreFailure(callable $operation): void
    {
        try {
            $operation();
        } catch (StoreException) {
            return;
        }

        throw new RuntimeException('A Redis failure was silently accepted.');
    }

    private function throwsConfigurationFailure(callable $operation): void
    {
        try {
            $operation();
        } catch (ConfigurationException) {
            return;
        }

        throw new RuntimeException('An unsupported Redis topology was silently accepted.');
    }

    private function same(mixed $expected, mixed $actual): void
    {
        $this->assert($expected === $actual, 'Expected '.var_export($expected, true).', received '.var_export($actual, true).'.');
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
