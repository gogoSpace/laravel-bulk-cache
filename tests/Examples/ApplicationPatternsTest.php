<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Examples;

use GogoSpace\BulkCache\Examples\ExampleEnvironment;
use GogoSpace\BulkCache\Examples\FavoriteApplication;
use GogoSpace\BulkCache\Exceptions\StoreException;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/examples/Support/ExampleEnvironment.php';
require_once dirname(__DIR__, 2).'/examples/Support/FavoriteApplication.php';

final class ApplicationPatternsTest extends TestCase
{
    public static function clients(): array
    {
        return ['PhpRedis' => ['phpredis'], 'Predis' => ['predis']];
    }

    #[DataProvider('clients')]
    public function test_durable_recovery_example_uses_a_fresh_process_and_duplicate_delivery(string $exampleRedisClient): void
    {
        $result = require dirname(__DIR__, 2).'/examples/recovery.php';
        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['saved_despite_cache_failure']);
        $this->assertTrue($result['durable_intent_recovered_by_fresh_process']);
        $this->assertSame([101], $result['stale_entry_survived_outage']);
        $this->assertSame([102], $result['authoritative_during_outage']);
        $this->assertSame([102], $result['after_retry_and_duplicate']);
        $this->assertSame([], $result['pending']);
        $this->assertSame($exampleRedisClient, $result['client']);
    }

    #[DataProvider('clients')]
    public function test_dataset_example_covers_variants_external_writers_and_safe_switch_back(string $exampleRedisClient): void
    {
        $result = require dirname(__DIR__, 2).'/examples/dataset-design.php';
        $this->assertSame('passed', $result['status']);
        $this->assertSame(['all' => [], 'stationery' => [], 'supplies' => []], $result['empty_aggregate_cached']);
        $this->assertSame(0, $result['external_bulk_write_model_events']);
        $this->assertSame(['all' => [102], 'stationery' => [], 'supplies' => [102]], $result['all_variants_after_write']);
        $this->assertTrue($result['other_user_stayed_warm']);
        $this->assertSame(2, $result['shared_catalog_loads']);
        $this->assertSame('2026-01-01', $result['eloquent_date']);
        $this->assertSame([102], $result['legacy_after_switch_back']);
        $this->assertSame([101], $result['old_legacy_entry']);
        $this->assertSame($exampleRedisClient, $result['client']);
    }

    public function test_write_between_the_initial_revision_check_and_cache_read_is_detected(): void
    {
        $environment = new ExampleEnvironment;
        try {
            $favorites = new FavoriteApplication($environment->application);
            $favorites->install();
            $favorites->read(1);
            $changed = false;
            $environment->application['db']->listen(function ($query) use ($environment, &$changed): void {
                if ($changed || ! str_contains($query->sql, 'AS pending')) {
                    return;
                }
                // QueryExecuted runs after the first metadata query fetched its old result.
                $changed = true;
                $environment->allowCacheCommands(false);
                $write = $environment->worker('write', ['user' => 1, 'product' => 102]);
                $this->assertSame('pending', $write['delivery']);
                $environment->allowCacheCommands(true);
            });
            $this->assertSame([102], $favorites->read(1)['all']);
            $this->assertTrue($changed);
            $this->assertSame(1, $favorites->aggregateLoads[1], 'The old cached value was read; the final revision check had to reject it.');
        } finally {
            $environment->close();
        }
    }

    public function test_source_change_and_intent_are_rolled_back_together(): void
    {
        $environment = new ExampleEnvironment;
        try {
            $favorites = new FavoriteApplication($environment->application);
            $favorites->install();
            $connection = $environment->application['db']->connection();
            $connection->unprepared("CREATE TRIGGER reject_intent BEFORE INSERT ON example_invalidations BEGIN SELECT RAISE(ABORT, 'intent unavailable'); END");
            try {
                $favorites->selectFavorite(1, 102);
                $this->fail('An intent storage failure must reject the entire source transaction.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('intent unavailable', $exception->getMessage());
            }
            $this->assertSame(1, (int) $connection->table('example_users')->where('id', 1)->value('revision'));
            $this->assertSame([101], $favorites->read(1)['all']);
            $this->assertSame([], $favorites->pending());
        } finally {
            $environment->close();
        }
    }

    public function test_replaying_an_older_intent_does_not_acknowledge_a_newer_write(): void
    {
        $environment = new ExampleEnvironment;
        try {
            $favorites = new FavoriteApplication($environment->application);
            $favorites->install();
            $older = $favorites->selectFavorite(1, 102);
            $favorites->deliver($older);
            $newer = $favorites->selectFavorite(1, 101);
            $environment->worker('deliver', ['intents' => [$older]]);
            $this->assertSame([$newer], $favorites->pending());
            $this->assertSame([101], $favorites->read(1)['all']);
            $environment->worker('deliver');
            $this->assertSame([], $favorites->pending());
            $this->assertSame([101], $favorites->read(1)['all']);
        } finally {
            $environment->close();
        }
    }

    public function test_dirty_source_failure_is_not_hidden_by_a_cache_fallback(): void
    {
        $environment = new ExampleEnvironment;
        try {
            $favorites = new FavoriteApplication($environment->application);
            $favorites->install();
            $favorites->read(1);
            $favorites->selectFavorite(1, 102);
            $environment->application['db']->statement('DROP TABLE example_favorites');
            $this->expectException(QueryException::class);
            $favorites->read(1);
        } finally {
            $environment->close();
        }
    }

    public function test_clean_cache_failure_propagates_instead_of_using_a_catch_all_fallback(): void
    {
        $environment = new ExampleEnvironment;
        try {
            $favorites = new FavoriteApplication($environment->application);
            $favorites->install();
            $favorites->read(1);
            $environment->allowCacheCommands(false);
            $this->expectException(StoreException::class);
            $favorites->read(1);
        } finally {
            $environment->close();
        }
    }
}
