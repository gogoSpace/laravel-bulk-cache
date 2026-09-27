<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Engine;
use GogoSpace\BulkCache\Events\CacheEvent;
use GogoSpace\BulkCache\Exceptions\CleanupException;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\RefreshScheduler;
use GogoSpace\BulkCache\Support\Claim;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\LoadContext;
use GogoSpace\BulkCache\Support\Observations;
use GogoSpace\BulkCache\Support\Options;
use GogoSpace\BulkCache\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use RuntimeException;

final class ObservationsTest extends TestCase
{
    private array $events = [];

    private ObservationClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new ObservationClock;
        $this->app->instance(Clock::class, $this->clock);
        $this->app['events']->listen(CacheEvent::class, function (CacheEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function test_disabled_observations_emit_nothing(): void
    {
        $manager = $this->useStore('array');
        $scope = $manager->scope('private-dynamic-name');
        $scope->rememberMany(['private-key'], 60, fn () => ['private-key' => 'private-value']);
        $scope->invalidateScope();
        self::assertSame([], $this->events);
    }

    public function test_cold_warm_stale_and_invalidation_events_contain_only_safe_aggregate_fields(): void
    {
        $this->app['config']->set('bulk-cache.events', true);
        $this->app['config']->set('bulk-cache.datasets.preferences', []);
        $scope = $this->useStore('array')->scope('preferences', ['user' => 'private-user']);
        $loader = function (): array {
            $this->clock->sleep(7);

            return ['private-key' => 'private-value'];
        };
        $scope->flexibleMany(['private-key'], Freshness::seconds(1, 10), $loader, 'inline');
        $scope->rememberMany(['private-key'], 1, $loader);
        $this->clock->sleep(2000);
        $scheduler = $this->app->make(RefreshScheduler::class);
        $scheduler->beginRequest();
        $scope->flexibleMany(['private-key'], Freshness::seconds(1, 10), $loader);
        $scheduler->finish(200);
        $scope->invalidateMany(['private-key']);
        $scope->invalidateScope();
        foreach (['cache.read', 'cache.claim', 'cache.publish', 'cache.release', 'loader', 'refresh.schedule', 'refresh.defer', 'invalidation.keys', 'invalidation.scope'] as $operation) {
            self::assertNotEmpty($this->matching($operation), $operation);
        }
        foreach (['miss', 'hit', 'stale'] as $status) {
            self::assertNotEmpty($this->matching('cache.result', $status));
        }
        self::assertSame(7.0, $this->matching('loader', 'success')[0]->milliseconds);
        foreach ($this->events as $event) {
            self::assertSame(['dataset', 'operation', 'status', 'count', 'milliseconds'], array_keys(get_object_vars($event)));
            self::assertSame('preferences', $event->dataset);
            self::assertGreaterThanOrEqual(0, $event->count);
            self::assertGreaterThanOrEqual(0, $event->milliseconds);
        }
        $encoded = json_encode($this->events, JSON_THROW_ON_ERROR);
        foreach (['private-user', 'private-key', 'private-value'] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_dynamic_dataset_names_use_one_bounded_label(): void
    {
        $this->app['config']->set('bulk-cache.events', true);
        $manager = $this->useStore('array');
        foreach (['user-123', 'user-456'] as $name) {
            $manager->scope($name)->rememberMany(['key'], 60, fn () => ['key' => 'value']);
        }
        self::assertNotEmpty($this->events);
        self::assertSame(['unregistered'], array_values(array_unique(array_map(fn (CacheEvent $event) => $event->dataset, $this->events))));
    }

    public function test_listener_failure_does_not_change_returned_values_or_invalidation(): void
    {
        $this->app['config']->set('bulk-cache.events', true);
        $this->app['events']->listen(CacheEvent::class, fn () => throw new RuntimeException('Listener failed'));
        $scope = $this->useStore('array')->scope('example');
        self::assertSame(['key' => 'before'], $scope->rememberMany(['key'], 60, fn () => ['key' => 'before']));
        $scope->invalidateScope();
        self::assertSame(['key' => 'after'], $scope->rememberMany(['key'], 60, fn () => ['key' => 'after']));
    }

    public function test_loader_cleanup_and_listener_failure_preserve_all_primary_causes(): void
    {
        $store = new ObservationStore;
        $store->releaseFailure = new StoreException('cleanup', phase: 'release');
        $sourceFailure = new RuntimeException('private-source-error');
        $observations = new Observations(fn () => throw new RuntimeException('listener'), 'dataset', $this->clock);
        try {
            $this->read($store, fn () => throw $sourceFailure, $observations);
            self::fail('Source and cleanup failure must propagate.');
        } catch (CleanupException $exception) {
            self::assertSame($sourceFailure, $exception->primaryFailure);
            self::assertSame([$store->releaseFailure], $exception->cleanupFailures);
            self::assertNotInstanceOf(StoreException::class, $exception);
            self::assertGreaterThan(0, $observations->listenerFailures());
        }
    }

    public function test_rejection_and_wait_events_measure_retry_without_serving_rejected_source(): void
    {
        $store = new ObservationStore;
        $store->rejectOnce = true;
        $observations = new Observations(function (CacheEvent $event): void {
            $this->events[] = $event;
        }, 'dataset', $this->clock);
        $calls = 0;
        $values = $this->read($store, function () use (&$calls): array {
            return ['key' => ++$calls === 1 ? 'rejected' : 'accepted'];
        }, $observations);
        self::assertSame(['key' => 'accepted'], $values);
        self::assertNotEmpty($this->matching('publication', 'rejected'));
        self::assertNotEmpty($this->matching('retry'));
        self::assertNotEmpty($this->matching('wait'));
        self::assertGreaterThan(0, $this->matching('wait')[0]->milliseconds);
    }

    public function test_deferred_source_failure_is_reported_with_no_exception_details_in_event(): void
    {
        $this->app['config']->set('bulk-cache.events', true);
        $scope = $this->useStore('array')->scope('example');
        $freshness = Freshness::seconds(1, 10);
        $scope->flexibleMany(['key'], $freshness, fn () => ['key' => 'old'], 'inline');
        $this->clock->sleep(2000);
        $scheduler = $this->app->make(RefreshScheduler::class);
        $scheduler->beginRequest();
        $scope->flexibleMany(['key'], $freshness, fn () => throw new RuntimeException('private-refresh-error'));
        $scheduler->finish(200);
        self::assertNotEmpty($this->matching('refresh.defer', 'error'));
        self::assertNotEmpty($this->matching('loader', 'error'));
        self::assertStringNotContainsString('private-refresh-error', json_encode($this->events, JSON_THROW_ON_ERROR));
    }

    public function test_partial_invalidation_across_chunks_retains_phase_and_emits_error(): void
    {
        $this->app['config']->set('bulk-cache.events', true);
        $this->app['config']->set('bulk-cache.batch_size', 2);
        $repository = new FailingInvalidationRepository(new ArrayStore);
        $this->app->instance('cache', new class($repository)
        {
            public function __construct(private Repository $repository) {}

            public function store(?string $name): Repository
            {
                return $this->repository;
            }
        });
        $scope = $this->app->make(BulkCacheManager::class)->scope('example');
        $scope->rememberMany(['first', 'second', 'third'], 60, fn (array $keys): array => array_fill_keys($keys, 'old'));
        try {
            $scope->invalidateMany(['first', 'second', 'third']);
            self::fail('The third invalidation must fail.');
        } catch (StoreException $exception) {
            self::assertSame('invalidate', $exception->phase);
            self::assertSame('partial', $exception->outcome);
            self::assertSame(2, $exception->completedOperations);
            self::assertNotEmpty($this->matching('invalidation.keys', 'error'));
        }
        self::assertSame(['third' => 'old'], $scope->rememberMany(['third'], 60, fn () => self::fail('The failed removal must leave the original cached value.')));
        self::assertSame(['first' => 'new', 'second' => 'new'], $scope->rememberMany(['first', 'second'], 60, fn (array $keys) => array_fill_keys($keys, 'new')));
    }

    public function test_queue_definition_failure_is_observed_without_loading(): void
    {
        $this->app['config']->set('bulk-cache.events', true);
        $scope = $this->useStore('array')->scope('example');
        try {
            $scope->refreshMany(['key'], Freshness::seconds(60), 'obsolete-definition');
            self::fail('A changed definition must be rejected.');
        } catch (ConfigurationException) {
            self::assertNotEmpty($this->matching('refresh.queue', 'error'));
            self::assertSame([], $this->matching('loader'));
        }
    }

    public function test_connection_failure_is_observed_and_preserves_the_original_backend_cause(): void
    {
        $this->app['config']->set('bulk-cache.events', true);
        $original = new RuntimeException('private-connection-secret');
        $this->app->bind('cache', function () use ($original): never {
            $this->clock->sleep(9);
            throw $original;
        });
        try {
            $this->app->make(BulkCacheManager::class)->scope('example')->rememberMany(['key'], 60, fn () => self::fail('Connection failure cannot invoke a loader.'));
            self::fail('Connection failure must propagate.');
        } catch (StoreException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame('connect', $exception->phase);
            self::assertSame('not_applied', $exception->outcome);
            $events = $this->matching('cache.connect', 'error');
            self::assertCount(1, $events);
            self::assertSame(9.0, $events[0]->milliseconds);
            self::assertStringNotContainsString('private-connection-secret', json_encode($events, JSON_THROW_ON_ERROR));
        }
    }

    private function matching(string $operation, ?string $status = null): array
    {
        return array_values(array_filter($this->events, fn (CacheEvent $event) => $event->operation === $operation && ($status === null || $event->status === $status)));
    }

    private function read(Store $store, callable $loader, Observations $observations): array
    {
        return (new Engine($this->clock, new LoadContext))->read($store, 'scope', ['key'], Freshness::seconds(60), $loader, new Options($this->app['config']->get('bulk-cache')), false, $observations)['values'];
    }
}

final class ObservationClock extends Clock
{
    private float $milliseconds = 1000000;

    public function now(): float
    {
        return $this->milliseconds / 1000;
    }

    public function monotonic(): float
    {
        return $this->milliseconds;
    }

    public function sleep(int $milliseconds): void
    {
        $this->milliseconds += $milliseconds;
    }
}

final class ObservationStore implements Store
{
    public ?StoreException $releaseFailure = null;

    public bool $rejectOnce = false;

    private array $values = [];

    public function readMany(string $scope, array $keys): array
    {
        return array_replace(array_fill_keys($keys, null), $this->values);
    }

    public function claim(string $scope, string $key, int $leaseMilliseconds): ?Claim
    {
        return new Claim('generation', 'owner');
    }

    public function publish(string $scope, string $key, Claim $claim, string $payload, int $retentionMilliseconds): bool
    {
        if ($this->rejectOnce) {
            $this->rejectOnce = false;

            return false;
        }
        $this->values[$key] = $payload;

        return true;
    }

    public function release(string $scope, string $key, Claim $claim): void
    {
        if ($this->releaseFailure !== null) {
            throw $this->releaseFailure;
        }
    }

    public function invalidateMany(string $scope, array $keys): void {}

    public function invalidateScope(string $scope): void {}
}

final class FailingInvalidationRepository extends Repository
{
    private int $removals = 0;

    public function forget($key)
    {
        if (++$this->removals === 3) {
            throw new RuntimeException('Synthetic invalidation failure');
        }

        return parent::forget($key);
    }
}
