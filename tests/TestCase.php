<?php

namespace GogoSpace\BulkCache\Tests;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\BulkCacheServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($application): array
    {
        return [BulkCacheServiceProvider::class];
    }

    protected function defineEnvironment($application): void
    {
        $application['config']->set('cache.default', 'array');
        $application['config']->set('bulk-cache.prefix', 'contract-'.bin2hex(random_bytes(8)));
        $application['config']->set('bulk-cache.driver', 'portable');
        $application['config']->set('bulk-cache.store', 'array');
        $application['config']->set('database.default', 'testing');
        $application['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function useStore(string $store): BulkCacheManager
    {
        $this->app['config']->set('bulk-cache.store', $store);

        if ($store === 'database') {
            $this->app['config']->set('cache.stores.database', [
                'driver' => 'database',
                'connection' => 'testing',
                'table' => 'cache',
            ]);
            $this->app['db']->connection()->getSchemaBuilder()->create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        return $this->app->make(BulkCacheManager::class);
    }

    public static function portableStores(): array
    {
        return [
            'array' => ['array'],
            'file' => ['file'],
            'SQLite database' => ['database'],
        ];
    }
}
