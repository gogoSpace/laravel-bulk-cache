<?php

declare(strict_types=1);

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Examples\ExampleEnvironment;
use GogoSpace\BulkCache\Examples\Maintenance\DedicatedPortableStore;
use GogoSpace\BulkCache\Examples\Maintenance\Process;
use GogoSpace\BulkCache\Examples\Maintenance\RedisInventory;
use GogoSpace\BulkCache\Support\Identity;
use Illuminate\Database\Schema\Blueprint;

require_once __DIR__.'/Support/ExampleEnvironment.php';
require_once __DIR__.'/Maintenance/RedisInventory.php';
require_once __DIR__.'/Maintenance/DedicatedPortableStore.php';
require_once __DIR__.'/Maintenance/Process.php';

$environment = new ExampleEnvironment($exampleRedisClient ?? null);
try {
    $connection = $environment->application['redis']->connection();
    $prefix = 'owned-retention-example';
    $dataset = ['name' => 'maintenance-user-v1', 'dimensions' => ['user' => 1], 'keys' => ['aggregate', 'filtered']];
    $inventory = new RedisInventory($connection, $prefix, [$dataset]);
    $scope = Identity::scope($dataset['name'], $dataset['dimensions']);
    foreach ($dataset['keys'] as $key) {
        $item = hash('sha256', $key);
        $claim = $inventory->store->claim($scope, $item, 30_000);
        ExampleEnvironment::check($inventory->store->publish($scope, $item, $claim, 'deleted-user-data', 300_000), 'Could not seed the owned Redis payload.');
    }
    $otherDataset = ['name' => 'maintenance-user-v1', 'dimensions' => ['user' => 2], 'keys' => ['aggregate']];
    $otherScope = Identity::scope($otherDataset['name'], $otherDataset['dimensions']);
    $item = hash('sha256', 'aggregate');
    $otherUser = new RedisInventory($connection, $prefix, [$otherDataset]);
    $otherClaim = $otherUser->store->claim($otherScope, $item, 30_000);
    $otherUser->store->publish($otherScope, $item, $otherClaim, 'other-user-data', 300_000);
    $otherApplication = new RedisInventory($connection, 'another-application', [$dataset]);
    $foreignClaim = $otherApplication->store->claim($scope, $item, 30_000);
    $otherApplication->store->publish($scope, $item, $foreignClaim, 'other-application-data', 300_000);

    // Production retirement must stop new work for this user/epoch first.
    // The only remaining producer in this controlled scenario is held after its claim.
    $producer = new Process($environment->configuration, 'redis-held', ['prefix' => $prefix, 'scope' => $scope, 'key' => $item]);
    $producer->receive('ready');
    $before = $inventory->sample();
    ExampleEnvironment::check($before['existing_keys'] >= 4 && $before['bytes'] > 0, 'The sample must include real generation, payload and lease memory.');
    $inventory->revokeExistingProducers();
    $cursor = 0;
    $redisBatches = [];
    do {
        $batch = $inventory->purgeBatch($cursor, 2);
        ExampleEnvironment::check($batch['attempted'] <= 2, 'Redis cleanup exceeded its key mutation budget.');
        $redisBatches[] = $batch['attempted'];
        $cursor = $batch['next'];
    } while (! $batch['complete']);
    $after = $inventory->sample();
    ExampleEnvironment::check($after['existing_keys'] === 0 && $after['bytes'] === 0, 'Owned values, leases and generation metadata must be physically absent.');
    $producer->release('erased');
    $afterErase = $producer->receive('erased-result');
    ExampleEnvironment::check($afterErase['accepted'] === false, 'A held producer must not publish after generation metadata is erased.');
    $newClaim = $inventory->store->claim($scope, $item, 30_000);
    ExampleEnvironment::check($inventory->store->publish($scope, $item, $newClaim, 'new-generation-data', 300_000), 'The recreated scope must accept its new owner.');
    $producer->release('recreated');
    $afterRecreate = $producer->receive('recreated-result');
    $producer->join();
    ExampleEnvironment::check($afterRecreate['accepted'] === false && $inventory->store->readMany($scope, [$item])[$item] === 'new-generation-data', 'An old producer must not publish into recreated metadata.');
    ExampleEnvironment::check($otherUser->store->readMany($otherScope, [$item])[$item] === 'other-user-data' && $otherApplication->store->readMany($scope, [$item])[$item] === 'other-application-data', 'Redis cleanup must preserve the other user and application.');
    // Remove the deliberately recreated probe data as well.
    for ($cursor = 0; $cursor < count($inventory->keys); $cursor += 2) {
        $inventory->purgeBatch($cursor, 2);
    }

    $portable = [];
    foreach (['file', 'database'] as $driver) {
        $directory = $environment->configuration['directory'].'/dedicated-'.$driver;
        $dedicated = new DedicatedPortableStore($environment->application, $directory, $driver);
        $dedicated->run(fn (BulkCacheManager $manager): array => $manager->scope('maintenance-user-v1', ['user' => 1])->rememberMany(['old'], 300, static fn (): array => ['old' => 'deleted-user-data']));
        if ($driver === 'file') {
            $foreignPath = $environment->configuration['directory'].'/another-application-cache';
            mkdir($foreignPath);
            file_put_contents($foreignPath.'/keep', 'other-application-data');
        } else {
            $environment->application['db']->connection()->getSchemaBuilder()->create('another_application_cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('value');
            });
            $environment->application['db']->table('another_application_cache')->insert(['key' => 'keep', 'value' => 'other-application-data']);
        }
        $heldPortable = new Process($environment->configuration, 'portable-held', ['directory' => $directory, 'driver' => $driver]);
        $heldPortable->receive('ready');
        $blocked = ! $dedicated->beginRetirement();
        ExampleEnvironment::check($blocked && $dedicated->activeEpoch() === 1, 'Portable retirement must refuse cutover while a source loader is still active.');
        $heldPortable->release('finish');
        $heldPortable->receive('finished');
        $heldPortable->join();
        ExampleEnvironment::check($dedicated->beginRetirement() && $dedicated->activeEpoch() === 2, 'A drained portable store must move to a fresh dedicated epoch.');
        $portableBefore = $dedicated->sampleRetired();
        ExampleEnvironment::check($portableBefore['entries'] >= 3 && $portableBefore['bytes'] > 0, 'The retired store must contain payload and generation metadata, including the completed late producer.');
        $batches = [];
        do {
            $batch = $dedicated->purgeBatch(2);
            ExampleEnvironment::check($batch['removed'] <= 2, 'Portable cleanup exceeded its mutation budget.');
            $batches[] = $batch['removed'];
        } while (! $batch['complete']);
        $portableAfter = $dedicated->sampleRetired();
        $physicallyRemoved = $driver === 'file'
            ? ! is_dir($directory.'/cache-1')
            : ! $environment->application['db']->connection()->getSchemaBuilder()->hasTable('example_retired_cache_1');
        ExampleEnvironment::check($portableAfter === ['entries' => 0, 'bytes' => 0] && $physicallyRemoved, 'The dedicated retired directory or table must be physically absent.');
        $fresh = $dedicated->run(fn (BulkCacheManager $manager): array => $manager->scope('maintenance-user-v1', ['user' => 1])->rememberMany(['old', 'late'], 300, static fn (): array => ['old' => 'current-data', 'late' => 'current-data']));
        ExampleEnvironment::check($fresh === ['old' => 'current-data', 'late' => 'current-data'], 'Cutover must not resurrect old values, including a formerly held portable producer.');
        $preserved = $driver === 'file'
            ? file_get_contents($foreignPath.'/keep') === 'other-application-data'
            : $environment->application['db']->table('another_application_cache')->value('value') === 'other-application-data';
        ExampleEnvironment::check($preserved, 'Portable cleanup must preserve another application store.');
        $portable[$driver] = ['refused_while_producer_active' => $blocked, 'before' => $portableBefore, 'after' => $portableAfter, 'retired_store_physically_removed' => $physicallyRemoved, 'batch_mutations' => $batches, 'active_epoch' => $dedicated->activeEpoch(), 'other_application_preserved' => $preserved];
    }

    return [
        'status' => 'passed',
        'redis_before' => $before,
        'redis_after' => $after,
        'redis_batch_keys' => $redisBatches,
        'old_producer_accepted_after_erasure' => $afterErase['accepted'],
        'old_producer_accepted_after_recreation' => $afterRecreate['accepted'],
        'redis_other_user_and_application_preserved' => true,
        'portable' => $portable,
        'client' => $environment->configuration['client'],
    ];
} finally {
    $environment->close();
}
