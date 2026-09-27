<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Exceptions\InvalidKeyException;
use GogoSpace\BulkCache\Exceptions\LoaderException;
use GogoSpace\BulkCache\Exceptions\RecursiveLoadException;
use GogoSpace\BulkCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LoaderContractTest extends TestCase
{
    #[DataProvider('invalidOutputs')]
    public function test_entire_loader_chunk_is_validated_before_any_value_is_written(mixed $output): void
    {
        $scope = $this->useStore('array')->scope('invalid-output');

        try {
            $scope->rememberMany(['first', 'second'], 60, fn () => $output);
            self::fail('An invalid loader result must fail.');
        } catch (LoaderException) {
            $calls = [];
            $result = $scope->rememberMany(['first', 'second'], 60, function (array $keys) use (&$calls): array {
                $calls[] = $keys;

                return array_fill_keys($keys, 'recovered');
            });
            self::assertSame([['first', 'second']], $calls);
            self::assertSame(['first' => 'recovered', 'second' => 'recovered'], $result);
        }
    }

    public static function invalidOutputs(): array
    {
        return [
            'missing key' => [['first' => 'valid']],
            'extra key' => [['first' => 'valid', 'second' => 'valid', 'third' => 'unrequested']],
            'wrong key' => [['first' => 'valid', 'third' => 'wrong']],
            'invalid second value' => [['first' => 'valid', 'second' => new \stdClass]],
            'no array' => [null],
        ];
    }

    public function test_completed_chunks_remain_valid_when_a_later_chunk_fails(): void
    {
        $this->app['config']->set('bulk-cache.batch_size', 1);
        $scope = $this->useStore('array')->scope('partial-call');

        try {
            $scope->rememberMany(['first', 'second'], 60, fn (array $keys): array => $keys === ['first'] ? ['first' => 'saved'] : []);
            self::fail('The invalid second chunk must fail the call.');
        } catch (LoaderException) {
            self::assertSame(['first' => 'saved', 'second' => 'new'], $scope->rememberMany(['first', 'second'], 60, function (array $keys): array {
                self::assertSame(['second'], $keys);

                return ['second' => 'new'];
            }));
        }
    }

    public function test_recursive_load_is_detected_and_a_subsequent_call_can_recover(): void
    {
        $scope = $this->useStore('array')->scope('recursion');

        try {
            $scope->rememberMany(['item'], 60, fn (array $keys): array => $scope->rememberMany($keys, 60, fn (): array => ['item' => 'nested']));
            self::fail('Recursive loading must fail immediately.');
        } catch (RecursiveLoadException) {
            self::assertSame(['item' => 'recovered'], $scope->rememberMany(['item'], 60, fn (): array => ['item' => 'recovered']));
        }
    }

    public function test_a_nested_load_for_a_different_identity_is_allowed(): void
    {
        $scope = $this->useStore('array')->scope('nested');
        self::assertSame(['first' => 'second value'], $scope->rememberMany(['first'], 60, function () use ($scope): array {
            $nested = $scope->rememberMany(['second'], 60, fn (): array => ['second' => 'second value']);

            return ['first' => $nested['second']];
        }));
    }

    public function test_invalid_input_is_rejected_before_the_loader_runs(): void
    {
        $scope = $this->useStore('array')->scope('invalid-keys');
        $this->expectException(InvalidKeyException::class);
        $scope->rememberMany(['valid', true], 60, function (): never {
            self::fail('Invalid input must not reach the source.');
        });
    }

    public function test_nonpositive_duration_is_rejected_without_running_the_loader(): void
    {
        $scope = $this->useStore('array')->scope('invalid-duration');
        $this->expectException(ConfigurationException::class);
        $scope->rememberMany(['item'], 0, function (): never {
            self::fail('Invalid duration must not reach the source.');
        });
    }

    public function test_input_limit_applies_before_loading(): void
    {
        $this->app['config']->set('bulk-cache.max_keys', 2);
        $scope = $this->useStore('array')->scope('bounded-input');
        $this->expectException(InvalidKeyException::class);
        $scope->rememberMany(['one', 'two', 'three'], 60, function (): never {
            self::fail('An excessive input must not reach the source.');
        });
    }

    public function test_empty_input_does_not_call_the_loader(): void
    {
        $scope = $this->useStore('array')->scope('empty');
        self::assertSame([], $scope->rememberMany([], 60, function (): never {
            self::fail('An empty input must not reach the source.');
        }));
    }
}
