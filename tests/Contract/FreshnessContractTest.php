<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\Exceptions\TimeoutException;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Missing;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Tests\TestCase;

final class FreshnessContractTest extends TestCase
{
    private ControlledClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new ControlledClock;
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_stricter_reader_refreshes_a_longer_lived_writer_value(): void
    {
        $scope = $this->useStore('array')->scope('reader-policy');
        $scope->rememberMany(['item'], 3600, fn (): array => ['item' => 'old']);
        $this->clock->advance(11);

        self::assertSame(['item' => 'new'], $scope->rememberMany(['item'], 10, fn (): array => ['item' => 'new']));
    }

    public function test_final_age_recheck_reloads_a_hit_that_expires_during_another_load(): void
    {
        $scope = $this->useStore('array')->scope('decision-time');
        $scope->rememberMany(['warm'], 1, fn (): array => ['warm' => 'old']);
        $calls = [];

        $result = $scope->rememberMany(['warm', 'cold'], 10, function (array $keys) use (&$calls): array {
            $calls[] = $keys;
            if ($keys === ['cold']) {
                $this->clock->advance(2);
            }

            return array_fill_keys($keys, 'new');
        });

        self::assertSame([['cold'], ['warm']], $calls);
        self::assertSame(['warm' => 'new', 'cold' => 'new'], $result);
    }

    public function test_negative_cache_expires_without_stale_fallback(): void
    {
        $this->app['config']->set('bulk-cache.negative_seconds', 2);
        $scope = $this->useStore('array')->scope('negative');
        $freshness = Freshness::seconds(60, 300);
        self::assertSame(['item' => Missing::Value], $scope->flexibleMany(['item'], $freshness, fn (): array => ['item' => Missing::Value], refresh: 'inline'));
        $this->clock->advance(1);
        self::assertSame(['item' => Missing::Value], $scope->flexibleMany(['item'], $freshness, function (): never {
            self::fail('An unexpired negative entry is a cache hit.');
        }, refresh: 'inline'));
        $this->clock->advance(1);
        self::assertSame(['item' => 'created'], $scope->flexibleMany(['item'], $freshness, fn (): array => ['item' => 'created'], refresh: 'inline'));
    }

    public function test_origin_time_is_part_of_freshness_and_budget(): void
    {
        $this->app['config']->set('bulk-cache.operation_milliseconds', 100);
        $scope = $this->useStore('array')->scope('slow-origin');

        try {
            $scope->rememberMany(['item'], 60, function (): array {
                $this->clock->advance(0.101);

                return ['item' => 'too late'];
            });
            self::fail('A completed callback must still respect the operation deadline.');
        } catch (TimeoutException) {
            self::assertSame(['item' => 'recovered'], $scope->rememberMany(['item'], 60, fn (): array => ['item' => 'recovered']));
        }
    }
}

final class ControlledClock extends Clock
{
    private float $seconds = 1000.0;

    private float $milliseconds = 0.0;

    public function now(): float
    {
        return $this->seconds;
    }

    public function monotonic(): float
    {
        return $this->milliseconds;
    }

    public function sleep(int $milliseconds): void
    {
        $this->advance($milliseconds / 1000);
    }

    public function advance(float $seconds): void
    {
        $this->seconds += $seconds;
        $this->milliseconds += $seconds * 1000;
    }
}
