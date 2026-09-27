<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Contracts\Loader;
use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Stores\PortableStore;
use GogoSpace\BulkCache\Stores\RedisStore;
use GogoSpace\BulkCache\Support\Identity;
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
        $dataset = $configuration['datasets'][$name] ?? [];
        if (! is_array($dataset)) {
            throw new ConfigurationException('Dataset configuration must be an array.');
        }
        unset($configuration['datasets']);
        $options = new Options(array_replace($configuration, $dataset));

        return new Scope($this, $name, $dimensions, Identity::scope($name, $dimensions), $options);
    }

    public function store(Options $options): Store
    {
        $prefix = 'bc:v1:'.hash('sha256', $options->values['prefix'].':'.$options->values['driver']);
        if ($options->values['driver'] === 'redis') {
            if (! $this->container->bound('redis')) {
                throw new ConfigurationException('Guarded Redis requires the Laravel Redis integration and a supported client.');
            }

            return new RedisStore($this->container->make(Factory::class)->connection($options->values['connection']), $prefix);
        }
        $repository = $this->container->make('cache')->store($options->values['store']);
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
}
