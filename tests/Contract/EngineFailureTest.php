<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Engine;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Exceptions\TimeoutException;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Support\Claim;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\LoadContext;
use GogoSpace\BulkCache\Support\Options;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EngineFailureTest extends TestCase
{
    private FaultStore $store;

    private FaultClock $clock;

    private Engine $engine;

    private Options $options;

    protected function setUp(): void
    {
        $configuration = require dirname(__DIR__, 2).'/config/bulk-cache.php';
        $configuration['prefix'] = 'failure-contract';
        $configuration['wait_milliseconds'] = 20;
        $configuration['operation_milliseconds'] = 100;
        $this->options = new Options($configuration);
        $this->store = new FaultStore;
        $this->clock = new FaultClock;
        $this->engine = new Engine($this->clock, new LoadContext);
    }

    public function test_storage_read_failure_is_not_treated_as_a_miss(): void
    {
        $failure = new RuntimeException('Storage connection failed');
        $this->store->readFailure = $failure;
        try {
            $this->read(['item'], function (): never {
                self::fail('An unavailable cache must not become uncontrolled source work.');
            });
            self::fail('The store failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
            self::assertSame(0, $this->store->claims);
            self::assertSame(0, $this->store->publications);
        }
    }

    public function test_incomplete_batch_is_an_error_before_loading(): void
    {
        $this->store->incompleteRead = true;
        $this->expectException(StoreException::class);
        $this->read(['item'], function (): never {
            self::fail('Unprocessed items must not become source misses.');
        });
    }

    public function test_corrupt_envelope_is_never_served_or_replaced_as_a_miss(): void
    {
        $this->store->values[hash('sha256', 'item')] = 'corrupt';
        $this->expectException(StoreException::class);
        $this->read(['item'], function (): never {
            self::fail('Corrupt state must surface explicitly.');
        });
    }

    public function test_rejected_publication_is_retried_and_its_source_value_is_not_returned(): void
    {
        $this->store->rejectionsRemaining = 1;
        $sourceCalls = 0;
        $values = $this->read(['item'], function () use (&$sourceCalls): array {
            return ['item' => ++$sourceCalls === 1 ? 'rejected' : 'accepted'];
        });

        self::assertSame(['item' => 'accepted'], $values);
        self::assertSame(2, $sourceCalls);
        self::assertSame(2, $this->store->publications);
        self::assertSame(2, $this->store->releases);
    }

    public function test_partial_write_failure_releases_every_claim_and_preserves_accepted_values(): void
    {
        $this->store->failPublicationAt = 2;
        try {
            $this->read(['first', 'second'], fn (): array => ['first' => 'saved', 'second' => 'failed']);
            self::fail('An unconfirmed write must fail the call.');
        } catch (StoreException) {
            self::assertSame(2, $this->store->releases);
            self::assertCount(1, $this->store->values);
        }

        $this->store->failPublicationAt = null;
        $values = $this->read(['first', 'second'], function (array $keys): array {
            self::assertSame(['second'], $keys);

            return ['second' => 'recovered'];
        });
        self::assertSame(['first' => 'saved', 'second' => 'recovered'], $values);
    }

    public function test_callback_over_budget_does_not_publish_and_releases_ownership(): void
    {
        try {
            $this->read(['item'], function (): array {
                $this->clock->sleep(101);

                return ['item' => 'too late'];
            });
            self::fail('A returned callback result must still respect the deadline.');
        } catch (TimeoutException) {
            self::assertSame(0, $this->store->publications);
            self::assertSame(1, $this->store->releases);
            self::assertSame([], $this->store->values);
        }
    }

    public function test_busy_owner_wait_is_bounded_without_unprotected_source_loading(): void
    {
        $this->store->busy = true;
        try {
            $this->read(['item'], function (): never {
                self::fail('A follower must not bypass an unacquired claim.');
            });
            self::fail('A persistently busy owner must exhaust its bounded wait.');
        } catch (TimeoutException) {
            self::assertSame(20.0, $this->clock->monotonic());
            self::assertSame(0, $this->store->publications);
            self::assertSame(0, $this->store->releases);
            self::assertLessThanOrEqual(3, $this->store->claims);
        }
    }

    private function read(array $keys, callable $loader): array
    {
        return $this->engine->read($this->store, 'scope', $keys, Freshness::seconds(60), $loader, $this->options, false)['values'];
    }
}

final class FaultClock extends Clock
{
    private float $milliseconds = 0;

    public function now(): float
    {
        return 1000 + $this->milliseconds / 1000;
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

final class FaultStore implements Store
{
    public array $values = [];

    public ?RuntimeException $readFailure = null;

    public bool $incompleteRead = false;

    public bool $busy = false;

    public int $claims = 0;

    public int $publications = 0;

    public int $releases = 0;

    public int $rejectionsRemaining = 0;

    public ?int $failPublicationAt = null;

    public function readMany(string $scope, array $keys): array
    {
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }
        if ($this->incompleteRead) {
            return [];
        }

        return array_replace(array_fill_keys($keys, null), array_intersect_key($this->values, array_fill_keys($keys, true)));
    }

    public function claim(string $scope, string $key, int $leaseMilliseconds): ?Claim
    {
        $this->claims++;

        return $this->busy ? null : new Claim('generation', 'owner');
    }

    public function publish(string $scope, string $key, Claim $claim, string $payload, int $retentionMilliseconds): bool
    {
        $this->publications++;
        if ($this->publications === $this->failPublicationAt) {
            throw new StoreException('Injected write failure');
        }
        if ($this->rejectionsRemaining > 0) {
            $this->rejectionsRemaining--;

            return false;
        }
        $this->values[$key] = $payload;

        return true;
    }

    public function release(string $scope, string $key, Claim $claim): void
    {
        $this->releases++;
    }

    public function invalidateMany(string $scope, array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }
    }

    public function invalidateScope(string $scope): void
    {
        $this->values = [];
    }
}
