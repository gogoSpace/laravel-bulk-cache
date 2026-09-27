<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\Missing;
use GogoSpace\BulkCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class PortableContractTest extends TestCase
{
    #[DataProvider('portableStores')]
    public function test_values_round_trip_without_truthiness_or_null_ambiguity(string $store): void
    {
        $scope = $this->useStore($store)->scope('values');
        $values = ['null' => null, 'false' => false, 'zero' => 0, 'empty' => '', 'array' => [], 'missing' => Missing::Value];
        $loads = 0;
        $loader = function (array $keys) use (&$loads, $values): array {
            $loads++;
            self::assertSame(array_keys($values), $keys);

            return array_reverse($values, true);
        };

        self::assertSame($values, $scope->rememberMany(array_keys($values), 60, $loader));
        self::assertSame($values, $scope->rememberMany(array_keys($values), 60, $loader));
        self::assertSame(1, $loads);
    }

    #[DataProvider('portableStores')]
    public function test_deduplication_normalizes_integers_but_preserves_string_identity(string $store): void
    {
        $scope = $this->useStore($store)->scope('identities');
        $calls = [];
        $result = $scope->rememberMany([42, '42', '042', 0, -1, 'Příliš', 'a:b'], 60, function (array $keys) use (&$calls): array {
            $calls[] = $keys;

            return array_combine($keys, $keys);
        });

        self::assertSame([['42', '042', '0', '-1', 'Příliš', 'a:b']], $calls);
        self::assertSame([42 => '42', '042' => '042', 0 => '0', -1 => '-1', 'Příliš' => 'Příliš', 'a:b' => 'a:b'], $result);
    }

    #[DataProvider('portableStores')]
    public function test_only_misses_are_loaded_and_chunks_are_bounded(string $store): void
    {
        $this->app['config']->set('bulk-cache.batch_size', 2);
        $scope = $this->useStore($store)->scope('chunks');
        $scope->rememberMany(['b'], 60, fn (array $keys): array => ['b' => 'cached']);
        $calls = [];

        $result = $scope->rememberMany(['a', 'b', 'c', 'd', 'e', 'a'], 60, function (array $keys) use (&$calls): array {
            $calls[] = $keys;

            return array_combine($keys, array_map(strtoupper(...), $keys));
        });

        self::assertSame(['a' => 'A', 'b' => 'cached', 'c' => 'C', 'd' => 'D', 'e' => 'E'], $result);
        self::assertSame(['a', 'c', 'd', 'e'], array_merge(...$calls));
        foreach ($calls as $keys) {
            self::assertLessThanOrEqual(2, count($keys));
        }
    }

    #[DataProvider('portableStores')]
    public function test_item_and_scope_invalidation_are_isolated(string $store): void
    {
        $manager = $this->useStore($store);
        $first = $manager->scope('catalog', ['tenant' => 'first']);
        $second = $manager->scope('catalog', ['tenant' => 'second']);
        $otherDataset = $manager->scope('preferences', ['tenant' => 'first']);
        $loader = fn (array $keys): array => array_fill_keys($keys, 'original');
        foreach ([$first, $second, $otherDataset] as $scope) {
            $scope->rememberMany(['a', 'b'], 60, $loader);
        }

        $first->invalidateMany(['a']);
        self::assertSame(['a' => 'changed', 'b' => 'original'], $first->rememberMany(['a', 'b'], 60, fn (array $keys): array => array_fill_keys($keys, 'changed')));
        $first->invalidateScope();
        self::assertSame(['a' => 'reset', 'b' => 'reset'], $first->rememberMany(['a', 'b'], 60, fn (array $keys): array => array_fill_keys($keys, 'reset')));

        foreach ([$second, $otherDataset] as $scope) {
            self::assertSame(['a' => 'original', 'b' => 'original'], $scope->rememberMany(['a', 'b'], 60, function (): never {
                self::fail('Invalidation crossed the requested scope.');
            }));
        }
    }

    #[DataProvider('portableStores')]
    public function test_loader_failure_does_not_change_existing_values_or_cache_absence(string $store): void
    {
        $scope = $this->useStore($store)->scope('source-failure');
        $scope->rememberMany(['warm'], 60, fn (): array => ['warm' => 'original']);
        $failure = new RuntimeException('Origin unavailable');

        try {
            $scope->rememberMany(['warm', 'cold'], 60, static function () use ($failure): never {
                throw $failure;
            });
            self::fail('The origin exception must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(['warm' => 'original', 'cold' => 'recovered'], $scope->rememberMany(['warm', 'cold'], 60, function (array $keys): array {
            self::assertSame(['cold'], $keys);

            return ['cold' => 'recovered'];
        }));
    }
}
