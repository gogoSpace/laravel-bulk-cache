<?php

namespace GogoSpace\BulkCache\Tests\Unit;

use GogoSpace\BulkCache\Stores\PortableStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

final class PortableIdentityTest extends TestCase
{
    public function test_compact_physical_keys_preserve_isolation_and_fit_a_prefixed_database_column(): void
    {
        $repository = new RecordingRepository(new ArrayStore);
        $first = new PortableStore($repository, 'application-first');
        $second = new PortableStore($repository, 'application-second');
        $item = hash('sha256', 'same-item');

        $claim = $first->claim('first-scope', $item, 1000);
        self::assertTrue($first->publish('first-scope', $item, $claim, 'first', 60000));
        $claim = $first->claim('second-scope', $item, 1000);
        self::assertTrue($first->publish('second-scope', $item, $claim, 'second', 60000));
        $claim = $second->claim('first-scope', $item, 1000);
        self::assertTrue($second->publish('first-scope', $item, $claim, 'other application', 60000));
        $first->invalidateScope('first-scope');
        $claim = $first->claim('first-scope', $item, 1000);
        self::assertTrue($first->publish('first-scope', $item, $claim, 'new generation', 60000));

        $payloadKeys = array_keys(array_filter($repository->writtenValues, static fn (mixed $value): bool => in_array($value, ['first', 'second', 'other application', 'new generation'], true)));
        self::assertCount(4, $payloadKeys);
        $laravelCachePrefix = str_repeat('application-production-', 4);
        foreach ($repository->observedKeys as $physicalKey) {
            self::assertLessThanOrEqual(72, strlen($physicalKey));
            self::assertLessThanOrEqual(255, strlen($laravelCachePrefix.$physicalKey));
        }
        self::assertSame([$item => 'new generation'], $first->readMany('first-scope', [$item]));
        self::assertSame([$item => 'second'], $first->readMany('second-scope', [$item]));
        self::assertSame([$item => 'other application'], $second->readMany('first-scope', [$item]));
    }
}

final class RecordingRepository extends Repository
{
    public array $observedKeys = [];

    public array $writtenValues = [];

    public function get($key, $default = null): mixed
    {
        $this->observedKeys[] = $key;

        return parent::get($key, $default);
    }

    public function put($key, $value, $timeToLive = null)
    {
        $this->observedKeys[] = $key;
        $this->writtenValues[$key] = $value;

        return parent::put($key, $value, $timeToLive);
    }

    public function forever($key, $value)
    {
        $this->observedKeys[] = $key;

        return parent::forever($key, $value);
    }
}
