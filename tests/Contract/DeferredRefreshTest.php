<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\RefreshScheduler;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeferredRefreshTest extends TestCase
{
    private LifecycleClock $clock;

    private RefreshScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new LifecycleClock;
        $this->app->instance(Clock::class, $this->clock);
        $this->scheduler = $this->app->make(RefreshScheduler::class);
    }

    public function test_mixed_fresh_stale_and_cold_items_refresh_only_the_needed_subsets(): void
    {
        $scope = $this->useStore('array')->scope('mixed');
        $scope->rememberMany(['fresh'], 60, fn (): array => ['fresh' => 'fresh value']);
        $scope->flexibleMany(['stale'], Freshness::seconds(1, 10), fn (): array => ['stale' => 'stale value'], refresh: 'inline');
        $this->clock->advance(2);
        $calls = [];
        $this->scheduler->beginRequest();

        $values = $scope->flexibleMany(['fresh', 'stale', 'cold'], Freshness::seconds(60, 10), function (array $keys) use (&$calls): array {
            $calls[] = $keys;

            return array_fill_keys($keys, 'new value');
        });

        self::assertSame(['fresh' => 'fresh value', 'stale' => 'stale value', 'cold' => 'new value'], $values);
        self::assertSame([['cold']], $calls);
        $this->scheduler->finish(200);
        self::assertSame([['cold'], ['stale']], $calls);
        self::assertSame(['stale' => 'new value'], $scope->rememberMany(['stale'], 60, function (): never {
            self::fail('The deferred result must have been published.');
        }));
    }

    #[DataProvider('httpStatuses')]
    public function test_http_status_controls_refresh_and_callbacks_do_not_leak(int $status, bool $refreshExpected): void
    {
        $scope = $this->useStore('array')->scope('http-status');
        $freshness = Freshness::seconds(1, 10);
        $scope->flexibleMany(['item'], $freshness, fn (): array => ['item' => 'old'], refresh: 'inline');
        $this->clock->advance(2);
        $loads = 0;
        $this->scheduler->beginRequest();
        self::assertSame(['item' => 'old'], $scope->flexibleMany(['item'], $freshness, function () use (&$loads): array {
            $loads++;

            return ['item' => 'new'];
        }));
        self::assertSame(0, $loads);
        $this->scheduler->finish($status);
        self::assertSame($refreshExpected ? 1 : 0, $loads);
        $this->scheduler->beginRequest();
        $this->scheduler->finish(200);
        self::assertSame($refreshExpected ? 1 : 0, $loads);
    }

    public static function httpStatuses(): array
    {
        return [[200, true], [302, true], [404, false], [500, false]];
    }

    public function test_deferred_refresh_keeps_the_stricter_reader_policy(): void
    {
        $scope = $this->useStore('array')->scope('strict-defer');
        $scope->flexibleMany(['item'], Freshness::seconds(3600, 3600), fn (): array => ['item' => 'old'], refresh: 'inline');
        $this->clock->advance(11);
        $loads = 0;
        $this->scheduler->beginRequest();
        self::assertSame(['item' => 'old'], $scope->flexibleMany(['item'], Freshness::seconds(10, 5), function () use (&$loads): array {
            $loads++;

            return ['item' => 'new'];
        }));
        self::assertSame(0, $loads);
        $this->scheduler->finish(200);
        self::assertSame(1, $loads);
        self::assertSame(['item' => 'new'], $scope->rememberMany(['item'], 10, function (): never {
            self::fail('A longer writer policy must not suppress the stricter deferred refresh.');
        }));
    }

    public function test_explicit_defer_outside_http_fails_before_source_work(): void
    {
        $scope = $this->useStore('array')->scope('command-defer');
        $this->expectException(ConfigurationException::class);
        $scope->flexibleMany(['item'], Freshness::seconds(1, 10), function (): never {
            self::fail('Invalid lifecycle configuration must fail before loading.');
        }, refresh: 'defer');
    }

    public function test_new_request_discards_unfinished_previous_request_callbacks(): void
    {
        $scope = $this->useStore('array')->scope('abandoned-request');
        $freshness = Freshness::seconds(1, 10);
        $scope->flexibleMany(['item'], $freshness, fn (): array => ['item' => 'old'], refresh: 'inline');
        $this->clock->advance(2);
        $this->scheduler->beginRequest();
        $scope->flexibleMany(['item'], $freshness, function (): never {
            self::fail('A prior request callback must not run in the next request.');
        });
        $this->scheduler->beginRequest();
        $this->scheduler->finish(200);
        self::assertSame('inline', $this->scheduler->strategy('auto'));
    }
}

final class LifecycleClock extends Clock
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
