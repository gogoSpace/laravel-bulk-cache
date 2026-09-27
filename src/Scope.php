<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Support\Identity;
use GogoSpace\BulkCache\Support\Options;

final readonly class Scope
{
    public function __construct(
        private BulkCacheManager $manager,
        public string $name,
        public array $dimensions,
        private string $identity,
        private Options $options,
    ) {}

    /** @param iterable<int|string> $keys @return array<int|string,mixed> */
    public function rememberMany(iterable $keys, int $seconds, ?callable $loader = null): array
    {
        return $this->execute($keys, Freshness::seconds($seconds), $loader, 'inline');
    }

    /** @param iterable<int|string> $keys @return array<int|string,mixed> */
    public function flexibleMany(iterable $keys, Freshness $freshness, ?callable $loader = null, string $refresh = 'auto'): array
    {
        return $this->execute($keys, $freshness, $loader, $refresh);
    }

    /** @param iterable<int|string> $keys */
    public function invalidateMany(iterable $keys): void
    {
        $keys = Identity::keys($keys, $this->options->number('max_keys'));
        if ($keys === []) {
            return;
        }
        $observations = $this->manager->observations($this->name, $this->options);
        $invalidate = function () use ($keys): void {
            $store = $this->manager->store($this->options);
            $completed = 0;
            try {
                foreach (array_chunk($keys, $this->options->number('batch_size')) as $chunk) {
                    $store->invalidateMany($this->identity, array_map(fn (string $key): string => hash('sha256', $key), $chunk));
                    $completed += count($chunk);
                }
            } catch (StoreException $exception) {
                throw $exception->afterCompleted($completed);
            }
        };
        $observations === null ? $invalidate() : $observations->measure('invalidation.keys', $invalidate, count($keys));
    }

    public function invalidateScope(): void
    {
        $observations = $this->manager->observations($this->name, $this->options);
        $invalidate = fn () => $this->manager->store($this->options)->invalidateScope($this->identity);
        $observations === null ? $invalidate() : $observations->measure('invalidation.scope', $invalidate);
    }

    /** Internal queue execution uses the original reader's complete freshness policy. */
    public function refreshMany(array $keys, Freshness $freshness, string $definition): void
    {
        $observations = $this->manager->observations($this->name, $this->options);
        $refresh = function () use ($keys, $freshness, $definition): void {
            if (! hash_equals($this->definition(), $definition)) {
                throw new ConfigurationException('The registered dataset changed after this refresh was queued. Discard or re-dispatch the job.');
            }
            $this->execute($keys, $freshness, null, 'inline');
        };
        $observations === null ? $refresh() : $observations->measure('refresh.queue', $refresh, count($keys));
    }

    public function definition(): string
    {
        $definition = $this->options->values;
        unset($definition['events']);
        if (! ($definition['require_guarded'] ?? false)) {
            unset($definition['require_guarded']);
        }
        if (($definition['dimensions'] ?? []) === []) {
            unset($definition['dimensions']);
        }

        return hash('sha256', serialize([$this->name, $definition]));
    }

    private function execute(iterable $keys, Freshness $freshness, ?callable $loader, string $refresh): array
    {
        $keys = Identity::keys($keys, $this->options->number('max_keys'));
        if ($keys === []) {
            return [];
        }
        $scheduler = $this->manager->scheduler();
        $strategy = $scheduler->strategy($refresh);
        if ($strategy === 'queue' && ($loader !== null || ! isset($this->options->values['loader']))) {
            throw new ConfigurationException('Queue refresh requires a registered dataset loader and no callback.');
        }
        $loader ??= $this->manager->loader($this->options, $this->dimensions);
        $observations = $this->manager->observations($this->name, $this->options);
        $connect = fn () => $this->manager->store($this->options);
        $store = $observations === null ? $connect() : $observations->measure('cache.connect', $connect);
        $result = $this->manager->engine()->read($store, $this->identity, $keys, $freshness, $loader, $this->options, $strategy !== 'inline', $observations);
        $staleKeys = $result['stale'];
        if ($staleKeys !== []) {
            $scheduler->schedule(
                $strategy,
                $this,
                $staleKeys,
                $freshness,
                fn () => $this->manager->engine()->read($store, $this->identity, $staleKeys, $freshness, $loader, $this->options, false, $observations),
                $this->options,
            );
        }

        return $result['values'];
    }
}
