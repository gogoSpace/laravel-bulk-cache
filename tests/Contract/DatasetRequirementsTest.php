<?php

namespace GogoSpace\BulkCache\Tests\Contract;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Contracts\Loader;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Support\Diagnostics;
use GogoSpace\BulkCache\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DatasetRequirementsTest extends TestCase
{
    #[DataProvider('invalidDimensions')]
    public function test_required_dimensions_fail_before_resolving_any_cache_or_loader(array $dimensions): void
    {
        $this->app['config']->set('bulk-cache.datasets.preferences', [
            'dimensions' => ['tenant' => 'int', 'user' => 'int', 'locale' => 'string', 'preview' => 'bool'],
            'loader' => RequirementsLoader::class,
        ]);
        $this->app->bind(RequirementsLoader::class, fn () => self::fail('A rejected scope must not resolve its loader.'));
        $this->app->bind('cache', fn () => self::fail('A rejected scope must not touch cache.'));
        $this->expectException(ConfigurationException::class);
        $this->app->make(BulkCacheManager::class)->scope('preferences', $dimensions);
    }

    public static function invalidDimensions(): array
    {
        return [
            'missing all' => [[]],
            'missing user' => [['tenant' => 1, 'locale' => 'en', 'preview' => false]],
            'numeric string' => [['tenant' => 1, 'user' => '42', 'locale' => 'en', 'preview' => false]],
            'null' => [['tenant' => 1, 'user' => null, 'locale' => 'en', 'preview' => false]],
            'boolean coercion' => [['tenant' => 1, 'user' => 42, 'locale' => 'en', 'preview' => 0]],
            'string coercion' => [['tenant' => 1, 'user' => 42, 'locale' => 1, 'preview' => false]],
        ];
    }

    public function test_valid_typed_dimensions_preserve_other_explicit_dimensions(): void
    {
        $this->app['config']->set('bulk-cache.datasets.preferences.dimensions', ['user' => 'int']);
        $scope = $this->app->make(BulkCacheManager::class)->scope('preferences', ['user' => 42, 'locale' => 'en']);
        self::assertSame(['all' => []], $scope->rememberMany(['all'], 60, fn () => ['all' => []]));
    }

    public function test_guard_requirement_rejects_portable_before_even_empty_operation(): void
    {
        $this->app['config']->set('bulk-cache.datasets.protected.require_guarded', true);
        $this->app->bind('cache', fn () => self::fail('Guard mismatch must not touch cache.'));
        $this->expectException(ConfigurationException::class);
        $this->app->make(BulkCacheManager::class)->scope('protected')->rememberMany([], 60);
    }

    public function test_valid_redis_requirement_does_not_connect_until_used(): void
    {
        $this->app['config']->set('bulk-cache.datasets.protected', ['driver' => 'redis', 'require_guarded' => true]);
        $this->app->bind('redis', fn () => self::fail('Scope declaration does not connect.'));
        $scope = $this->app->make(BulkCacheManager::class)->scope('protected');
        self::assertSame([], $scope->rememberMany([], 60));
    }

    public function test_invalid_registered_loader_is_rejected_even_with_callback(): void
    {
        $this->app['config']->set('bulk-cache.datasets.preferences.loader', \stdClass::class);
        $this->expectException(ConfigurationException::class);
        $this->app->make(BulkCacheManager::class)->scope('preferences')->rememberMany(['item'], 60, fn () => self::fail('Invalid registration must fail first.'));
    }

    public function test_diagnostics_are_configuration_only_and_redact_private_values(): void
    {
        $this->app['config']->set('bulk-cache.prefix', 'secret-user-42-password');
        $this->app['config']->set('database.redis.diagnostic', ['host' => 'private-host', 'password' => 'never-print-password']);
        $this->app['config']->set('bulk-cache.datasets.preferences', ['driver' => 'redis', 'connection' => 'diagnostic', 'require_guarded' => true, 'loader' => RequirementsLoader::class, 'dimensions' => ['user' => 'int']]);
        $this->app->bind('cache', fn () => self::fail('Diagnostics must not resolve cache.'));
        $this->app->bind('redis', fn () => self::fail('Diagnostics must not resolve Redis.'));
        $this->app->bind(RequirementsLoader::class, fn () => self::fail('Diagnostics must not construct a loader.'));
        $report = $this->app->make(Diagnostics::class)->inspect();
        self::assertTrue($report['valid']);
        self::assertSame('not_checked', $report['availability']);
        self::assertSame('registered', $report['datasets'][1]['loader']);
        self::assertSame(['user'], $report['datasets'][1]['required_dimensions']);
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['secret-user-42-password', 'private-host', 'never-print-password'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $encoded);
        }
        $this->artisan('bulk-cache:diagnose --json')->assertSuccessful();
    }

    public function test_diagnostic_exit_fails_for_mismatched_guarantee_and_invalid_loader(): void
    {
        $this->app['config']->set('bulk-cache.datasets.invalid', ['require_guarded' => true, 'loader' => \stdClass::class]);
        $report = $this->app->make(Diagnostics::class)->inspect();
        self::assertFalse($report['valid']);
        self::assertCount(2, $report['datasets'][1]['errors']);
        $this->artisan('bulk-cache:diagnose --json')->assertFailed();
    }

    public function test_optional_defaults_keep_beta_one_queue_definition(): void
    {
        $configuration = $this->app['config']->get('bulk-cache');
        unset($configuration['datasets'], $configuration['events'], $configuration['require_guarded'], $configuration['dimensions']);
        $expected = hash('sha256', serialize(['preferences', $configuration]));
        $manager = $this->app->make(BulkCacheManager::class);
        self::assertSame($expected, $manager->scope('preferences')->definition());
        $this->app['config']->set('bulk-cache.events', true);
        self::assertSame($expected, $manager->scope('preferences')->definition());
        $this->app['config']->set('bulk-cache.datasets.preferences.dimensions', ['user' => 'int']);
        self::assertNotSame($expected, $manager->scope('preferences', ['user' => 42])->definition());
    }
}

final class RequirementsLoader implements Loader
{
    public function load(array $keys, array $dimensions): array
    {
        return array_fill_keys($keys, []);
    }
}
