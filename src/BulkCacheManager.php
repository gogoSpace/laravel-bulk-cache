<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Contracts\Loader;
use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Events\CacheEvent;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Stores\PortableStore;
use GogoSpace\BulkCache\Stores\RedisStore;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\Identity;
use GogoSpace\BulkCache\Support\Observations;
use GogoSpace\BulkCache\Support\Options;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory;

final class BulkCacheManager
{
    public function __construct(private Container $container) {}

    /** @param array<string, bool|int|string|null> $dimensions */
    public function scope(string $name, array $dimensions = []): Scope
    {
        $configuration = $this->container->make('config')->get('bulk-cache');
        if (! is_array($configuration) || ! is_array($configuration['datasets'] ?? null)) {
            throw new ConfigurationException('Bulk cache configuration and datasets must be arrays.');
        }
        $dataset = $configuration['datasets'][$name] ?? [];
        if (! is_array($dataset)) {
            throw new ConfigurationException('Dataset configuration must be an array.');
        }
        unset($configuration['datasets']);
        $options = new Options(array_replace($configuration, $dataset));
        $options->validateDimensions($dimensions);

        return new Scope($this, $name, $dimensions, Identity::scope($name, $dimensions), $options);
    }

    public function store(Options $options): Store
    {
        $prefix = 'bc:v1:'.hash('sha256', $options->values['prefix'].':'.$options->values['driver']);
        if ($options->values['driver'] === 'redis') {
            if (! $this->container->bound('redis')) {
                throw new ConfigurationException('Guarded Redis requires the Laravel Redis integration and a supported client.');
            }

            try {
                return new RedisStore($this->container->make(Factory::class)->connection($options->values['connection']), $prefix);
            } catch (ConfigurationException|StoreException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                throw new StoreException('The Redis connection could not be initialized.', previous: $exception, phase: 'connect', outcome: 'not_applied');
            }
        }
        try {
            $repository = $this->container->make('cache')->store($options->values['store']);
        } catch (\Throwable $exception) {
            throw new StoreException('The portable cache store could not be initialized.', previous: $exception, phase: 'connect', outcome: 'not_applied');
        }
        $underlying = $repository->getStore();
        if (! in_array(get_class($underlying), [ArrayStore::class, FileStore::class, DatabaseStore::class], true)) {
            throw new ConfigurationException('Portable mode supports Laravel array, file and database stores. Select an explicit supported store.');
        }

        return new PortableStore($repository, $prefix);
    }

    public function loader(Options $options, array $dimensions): callable
    {
        $class = $options->values['loader'] ?? null;
        if (! is_string($class) || ! is_subclass_of($class, Loader::class)) {
            throw new ConfigurationException('Provide a callback or register a dataset loader implementing Loader.');
        }

        return fn (array $keys): array => $this->container->make($class)->load($keys, $dimensions);
    }

    public function engine(): Engine
    {
        return $this->container->make(Engine::class);
    }

    public function scheduler(): RefreshScheduler
    {
        return $this->container->make(RefreshScheduler::class);
    }

    public function observations(string $name, Options $options): ?Observations
    {
        if (! ($options->values['events'] ?? false)) {
            return null;
        }
        $datasets = $this->container->make('config')->get('bulk-cache.datasets', []);
        $label = array_key_exists($name, $datasets) ? $name : 'unregistered';

        return new Observations(
            fn (CacheEvent $event) => $this->container->make('events')->dispatch($event),
            $label,
            $this->container->make(Clock::class),
        );
    }
}
