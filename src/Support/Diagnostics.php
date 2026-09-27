<?php

namespace GogoSpace\BulkCache\Support;

use GogoSpace\BulkCache\Contracts\Loader;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;

/** Configuration inspection only. It never resolves a store or a loader. */
final class Diagnostics
{
    public function __construct(private Container $container) {}

    public function inspect(): array
    {
        $configuration = $this->container->make('config');
        $defaults = $configuration->get('bulk-cache');
        if (! is_array($defaults) || ! is_array($defaults['datasets'] ?? null)) {
            return ['valid' => false, 'availability' => 'not_checked', 'errors' => ['Bulk cache configuration and datasets must be arrays.'], 'datasets' => []];
        }
        $datasets = $defaults['datasets'];
        unset($defaults['datasets']);
        $reports = [];
        foreach (['(defaults)' => [], ...$datasets] as $name => $overrides) {
            $errors = [];
            if (! is_array($overrides)) {
                $errors[] = 'Dataset configuration must be an array.';
                $overrides = [];
            }
            $values = array_replace($defaults, $overrides);
            try {
                new Options($values);
            } catch (ConfigurationException $exception) {
                $errors[] = $exception->getMessage();
            }
            $driver = in_array($values['driver'] ?? null, ['portable', 'redis'], true) ? $values['driver'] : 'invalid';
            $storeName = $values['store'] ?? $configuration->get('cache.default');
            $connectionName = $values['connection'] ?? 'default';
            $storeDriver = is_string($storeName) ? $configuration->get('cache.stores.'.$storeName.'.driver') : null;
            if ($driver === 'portable' && ! in_array($storeDriver, ['array', 'file', 'database'], true)) {
                $errors[] = 'Portable mode requires a configured array, file or database store.';
            }
            if ($driver === 'redis' && (! is_string($connectionName) || ! is_array($configuration->get('database.redis.'.$connectionName)))) {
                $errors[] = 'The named Redis connection is not configured.';
            }
            $loader = $values['loader'] ?? null;
            $loaderState = 'callback_only';
            if ($loader !== null) {
                $valid = is_string($loader) && is_subclass_of($loader, Loader::class) && (new ReflectionClass($loader))->isInstantiable();
                $loaderState = $valid ? 'registered' : 'invalid';
                if (! $valid) {
                    $errors[] = 'The registered loader must be an instantiable class implementing Loader.';
                }
            }
            $reports[] = [
                'dataset' => (string) $name,
                'driver' => $driver,
                'store' => $driver === 'portable' && is_string($storeName) ? $storeName : null,
                'store_driver' => $driver === 'portable' && in_array($storeDriver, ['array', 'file', 'database'], true) ? $storeDriver : null,
                'connection' => $driver === 'redis' && is_string($connectionName) ? $connectionName : null,
                'namespace_fingerprint' => is_string($values['prefix'] ?? null) ? substr(hash('sha256', $values['prefix']), 0, 16) : null,
                'guarded_publication' => $driver === 'redis',
                'require_guarded' => ($values['require_guarded'] ?? false) === true,
                'required_dimensions' => is_array($values['dimensions'] ?? null) ? array_keys($values['dimensions']) : [],
                'loader' => $loaderState,
                'errors' => $errors,
            ];
        }

        return ['valid' => array_filter($reports, fn (array $report): bool => $report['errors'] !== []) === [], 'availability' => 'not_checked', 'errors' => [], 'datasets' => $reports];
    }
}
