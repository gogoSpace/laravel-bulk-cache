<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Contracts\Loader;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Jobs\RefreshJob;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

final class QueueRefreshTest extends TestCase
{
    private QueueClock $clock;

    private BulkCacheManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new QueueClock;
        $this->app->instance(Clock::class, $this->clock);
        $this->app['config']->set('bulk-cache.datasets.queued', ['loader' => RegisteredQueueLoader::class]);
        $this->manager = $this->useStore('array');
        RegisteredQueueLoader::$calls = [];
        RegisteredQueueLoader::$value = 'old';
        Queue::fake();
    }

    public function test_serialized_job_preserves_reader_policy_and_context_then_rereads(): void
    {
        $dimensions = ['tenant' => 17, 'user' => 'alice'];
        $scope = $this->manager->scope('queued', $dimensions);
        self::assertSame(['item' => 'old'], $scope->flexibleMany(['item'], Freshness::seconds(3600, 3600), refresh: 'queue'));
        Queue::assertNothingPushed();
        $this->clock->advance(11);
        RegisteredQueueLoader::$value = 'new';
        self::assertSame(['item' => 'old'], $scope->flexibleMany(['item'], Freshness::seconds(10, 5), refresh: 'queue'));
        self::assertCount(1, RegisteredQueueLoader::$calls);
        Queue::assertPushed(RefreshJob::class, 1);
        $original = Queue::pushed(RefreshJob::class)->first();
        $restored = unserialize(serialize($original), ['allowed_classes' => [RefreshJob::class]]);
        self::assertInstanceOf(RefreshJob::class, $restored);
        self::assertSame(10, $restored->freshFor);
        self::assertSame(5, $restored->staleFor);
        self::assertSame($dimensions, $restored->dimensions);

        $restored->handle($this->manager);
        self::assertCount(2, RegisteredQueueLoader::$calls);
        self::assertSame(['keys' => ['item'], 'dimensions' => $dimensions], RegisteredQueueLoader::$calls[1]);
        self::assertSame(['item' => 'new'], $scope->rememberMany(['item'], 10));
        $restored->handle($this->manager);
        self::assertCount(2, RegisteredQueueLoader::$calls, 'A duplicate job must reread the fresh result.');
    }

    public function test_changed_dataset_definition_rejects_a_queued_job_before_loading(): void
    {
        $job = $this->queuedJob();
        $this->app['config']->set('bulk-cache.datasets.queued.batch_size', 10);
        $this->expectException(ConfigurationException::class);
        try {
            $job->handle($this->manager);
        } finally {
            self::assertCount(1, RegisteredQueueLoader::$calls);
        }
    }

    public function test_unrelated_dataset_registration_does_not_invalidate_a_job(): void
    {
        $job = $this->queuedJob();
        $this->app['config']->set('bulk-cache.datasets.unrelated', ['loader' => RegisteredQueueLoader::class]);
        $job->handle($this->manager);
        self::assertCount(2, RegisteredQueueLoader::$calls);
    }

    public function test_unsupported_job_version_is_rejected_before_loading(): void
    {
        $job = $this->queuedJob();
        $job->version = 99;
        $this->expectException(ConfigurationException::class);
        try {
            $job->handle($this->manager);
        } finally {
            self::assertCount(1, RegisteredQueueLoader::$calls);
        }
    }

    public function test_queue_callback_is_rejected_even_with_a_registered_loader(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->manager->scope('queued')->flexibleMany(['item'], Freshness::seconds(1, 10), function (): never {
            self::fail('A closure must not be used for queue refresh.');
        }, refresh: 'queue');
    }

    public function test_queue_requires_a_registered_loader(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->manager->scope('unregistered')->flexibleMany(['item'], Freshness::seconds(1, 10), refresh: 'queue');
    }

    private function queuedJob(): RefreshJob
    {
        $scope = $this->manager->scope('queued', ['tenant' => 17]);
        $scope->flexibleMany(['item'], Freshness::seconds(1, 10), refresh: 'queue');
        $this->clock->advance(2);
        $scope->flexibleMany(['item'], Freshness::seconds(1, 10), refresh: 'queue');
        Queue::assertPushed(RefreshJob::class, 1);

        return Queue::pushed(RefreshJob::class)->first();
    }
}

final class RegisteredQueueLoader implements Loader
{
    public static array $calls = [];

    public static string $value = 'old';

    public function load(array $keys, array $dimensions): array
    {
        self::$calls[] = ['keys' => $keys, 'dimensions' => $dimensions];

        return array_fill_keys($keys, self::$value);
    }
}

final class QueueClock extends Clock
{
    private float $seconds = 1000;

    public function now(): float
    {
        return $this->seconds;
    }

    public function monotonic(): float
    {
        return $this->seconds * 1000;
    }

    public function advance(float $seconds): void
    {
        $this->seconds += $seconds;
    }

    public function sleep(int $milliseconds): void
    {
        $this->advance($milliseconds / 1000);
    }
}
