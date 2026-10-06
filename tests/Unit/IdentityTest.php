<?php

namespace GogoSpace\BulkCache\Tests\Unit;

use GogoSpace\BulkCache\Exceptions\InvalidKeyException;
use GogoSpace\BulkCache\Support\Identity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    public function test_dimensions_are_order_independent_and_type_sensitive(): void
    {
        $first = Identity::scope('catalog', ['tenant' => 7, 'locale' => 'en']);
        self::assertSame($first, Identity::scope('catalog', ['locale' => 'en', 'tenant' => 7]));
        self::assertNotSame($first, Identity::scope('catalog', ['locale' => 'en', 'tenant' => '7']));
        self::assertNotSame($first, Identity::scope('other', ['locale' => 'en', 'tenant' => 7]));
        self::assertNotSame(Identity::scope('catalog', []), Identity::scope('catalog', ['user' => null]));
        self::assertNotSame(Identity::scope('catalog', ['user' => false]), Identity::scope('catalog', ['user' => 0]));
    }

    public function test_generator_is_consumed_once_and_limit_includes_duplicates(): void
    {
        $consumed = 0;
        $keys = (function () use (&$consumed): \Generator {
            foreach (['first', 'first', 'second', 'third'] as $key) {
                $consumed++;
                yield $key;
            }
        })();

        try {
            Identity::keys($keys, 2);
            self::fail('The input limit must stop a generator.');
        } catch (InvalidKeyException) {
            self::assertSame(3, $consumed);
        }
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_key_types_and_lengths_are_rejected(mixed $key): void
    {
        $this->expectException(InvalidKeyException::class);
        Identity::keys([$key], 10);
    }

    public static function invalidKeys(): array
    {
        return [
            'boolean key' => [true],
            'null key' => [null],
            'float key' => [1.5],
            'array key' => [[]],
            'object key' => [new \stdClass],
            'oversized key' => [str_repeat('a', 1025)],
        ];
    }

    #[DataProvider('invalidDimensions')]
    public function test_dimensions_require_bounded_named_scalars(array $dimensions): void
    {
        $this->expectException(InvalidKeyException::class);
        Identity::scope('catalog', $dimensions);
    }

    public static function invalidDimensions(): array
    {
        return [
            'nested value' => [['nested' => ['tenant' => 7]]],
            'float value' => [['fraction' => 1.5]],
            'unnamed dimension' => [[0 => 'unnamed']],
            'oversized value' => [['long' => str_repeat('x', 1025)]],
            'too many dimensions' => [array_fill_keys(array_map(fn (int $number): string => 'dimension-'.$number, range(1, 33)), true)],
        ];
    }
}
