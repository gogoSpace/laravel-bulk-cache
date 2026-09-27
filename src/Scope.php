<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;
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
        $store = $this->manager->store($this->options);
        foreach (array_chunk($keys, $this->options->number('batch_size')) as $chunk) {
            $store->invalidateMany($this->identity, array_map(fn (string $key): string => hash('sha256', $key), $chunk));
        }
    }

    public function invalidateScope(): void
    {
        $this->manager->store($this->options)->invalidateScope($this->identity);
    }

    /** Internal queue execution uses the original reader's complete freshness policy. */
    public function refreshMany(array $keys, Freshness $freshness, string $definition): void
    {
        if (! hash_equals($this->definition(), $definition)) {
            throw new ConfigurationException('The registered dataset changed after this refresh was queued. Discard or re-dispatch the job.');
        }
        $this->execute($keys, $freshness, null, 'inline');
    }

    public function definition(): string
    {
        return hash('sha256', serialize([$this->name, $this->options->values]));
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
        $store = $this->manager->store($this->options);
        $result = $this->manager->engine()->read($store, $this->identity, $keys, $freshness, $loader, $this->options, $strategy !== 'inline');
        $staleKeys = $result['stale'];
        if ($staleKeys !== []) {
            $scheduler->schedule(
                $strategy,
                $this,
                $staleKeys,
                $freshness,
                fn () => $this->manager->engine()->read($store, $this->identity, $staleKeys, $freshness, $loader, $this->options, false),
                $this->options,
            );
        }

        return $result['values'];
    }
}
