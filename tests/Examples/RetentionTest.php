<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Examples;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Examples\ExampleEnvironment;
use GogoSpace\BulkCache\Examples\Maintenance\DedicatedPortableStore;
use GogoSpace\BulkCache\Examples\Maintenance\RedisInventory;
use GogoSpace\BulkCache\Support\Identity;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/examples/Support/ExampleEnvironment.php';
require_once dirname(__DIR__, 2).'/examples/Maintenance/RedisInventory.php';
require_once dirname(__DIR__, 2).'/examples/Maintenance/DedicatedPortableStore.php';

final class RetentionTest extends TestCase
{
    public static function clients(): array
    {
        return ['PhpRedis' => ['phpredis'], 'Predis' => ['predis']];
    }

    public static function portableDrivers(): array
    {
        return ['file' => ['file'], 'database' => ['database']];
    }

    #[DataProvider('clients')]
    public function test_a_denied_delete_does_not_advance_the_cursor_and_can_be_retried(string $client): void
    {
        $environment = new ExampleEnvironment($client);
        try {
            $connection = $environment->application['redis']->connection();
            $dataset = ['name' => 'owned', 'dimensions' => ['user' => 1], 'keys' => ['one']];
            $inventory = new RedisInventory($connection, 'denied-maintenance', [$dataset]);
            $scope = Identity::scope('owned', ['user' => 1]);
            $key = hash('sha256', 'one');
            $claim = $inventory->store->claim($scope, $key, 30000);
            $this->assertTrue($inventory->store->publish($scope, $key, $claim, 'owned-data', 300000));
            $before = $inventory->sample();
            $connection->command('acl', ['SETUSER', 'default', '-del']);
            $cursor = 0;
            try {
                $result = $inventory->purgeBatch($cursor, 2);
                $cursor = $result['next'];
                $this->fail('A rejected DEL must not produce a successful progress result.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('NOPERM', $exception->getMessage());
            }
            $this->assertSame(0, $cursor);
            $this->assertSame($before, $inventory->sample());
            $connection->command('acl', ['SETUSER', 'default', '+del']);
            do {
                $result = $inventory->purgeBatch($cursor, 2);
                $cursor = $result['next'];
            } while (! $result['complete']);
            $this->assertSame(0, $inventory->sample()['existing_keys']);
        } finally {
            $environment->close();
        }
    }

    #[DataProvider('portableDrivers')]
    public function test_restart_recovers_prepared_next_store_and_removed_retired_store(string $driver): void
    {
        $environment = new ExampleEnvironment;
        try {
            $directory = $environment->configuration['directory'].'/restart-'.$driver;
            $store = new DedicatedPortableStore($environment->application, $directory, $driver);
            $schema = $environment->application['db']->connection()->getSchemaBuilder();
            // Simulate a stop after preparing epoch 2 but before persisting the epoch switch.
            if ($driver === 'file') {
                mkdir($directory.'/cache-2');
            } else {
                $schema->create('example_retired_cache_2', function (Blueprint $table): void {
                    $table->string('key')->primary();
                    $table->mediumText('value');
                    $table->integer('expiration');
                });
            }
            $this->assertSame(1, $store->activeEpoch());
            $this->assertTrue($store->beginRetirement());
            $this->assertSame(2, $store->activeEpoch());
            // Simulate a stop after the physical removal but before clearing retired state.
            if ($driver === 'file') {
                rmdir($directory.'/cache-1');
            } else {
                $schema->drop('example_retired_cache_1');
            }
            $restarted = new DedicatedPortableStore($environment->application, $directory, $driver);
            $this->assertSame(['removed' => 0, 'complete' => true], $restarted->purgeBatch(2));
            $this->assertSame(['removed' => 0, 'complete' => true], $restarted->purgeBatch(2));
            $this->assertSame(['entries' => 0, 'bytes' => 0], $restarted->sampleRetired());
            $values = $restarted->run(fn (BulkCacheManager $manager): array => $manager->scope('restart')->rememberMany(['one'], 300, static fn (): array => ['one' => 'current']));
            $this->assertSame(['one' => 'current'], $values);
        } finally {
            $environment->close();
        }
    }

    #[DataProvider('clients')]
    public function test_bounded_physical_retirement_isolated_from_other_users_and_applications(string $exampleRedisClient): void
    {
        $result = require dirname(__DIR__, 2).'/examples/retention.php';
        $this->assertSame('passed', $result['status']);
        $this->assertSame($exampleRedisClient, $result['client']);
        $this->assertGreaterThan(0, $result['redis_before']['bytes']);
        $this->assertSame(4, $result['redis_before']['existing_keys']);
        $this->assertSame(0, $result['redis_after']['existing_keys']);
        $this->assertSame(0, $result['redis_after']['bytes']);
        $this->assertSame([2, 2, 1], $result['redis_batch_keys']);
        $this->assertFalse($result['old_producer_accepted_after_erasure']);
        $this->assertFalse($result['old_producer_accepted_after_recreation']);
        $this->assertTrue($result['redis_other_user_and_application_preserved']);
        foreach (['file', 'database'] as $driver) {
            $portable = $result['portable'][$driver];
            $this->assertTrue($portable['refused_while_producer_active']);
            $this->assertGreaterThanOrEqual(3, $portable['before']['entries']);
            $this->assertGreaterThan(0, $portable['before']['bytes']);
            $this->assertSame(['entries' => 0, 'bytes' => 0], $portable['after']);
            $this->assertTrue($portable['retired_store_physically_removed']);
            $this->assertLessThanOrEqual(2, max($portable['batch_mutations']));
            $this->assertSame(2, $portable['active_epoch']);
            $this->assertTrue($portable['other_application_preserved']);
        }
    }
}
