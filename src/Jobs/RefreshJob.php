<?php

namespace GogoSpace\BulkCache\Jobs;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Freshness;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RefreshJob implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 1;

    public function __construct(
        public string $dataset,
        public array $dimensions,
        public array $keys,
        public int $freshFor,
        public int $staleFor,
        public string $definition,
        public int $version = 1,
    ) {}

    public function handle(BulkCacheManager $manager): void
    {
        if ($this->version !== 1) {
            throw new ConfigurationException('Unsupported bulk cache refresh job version.');
        }
        $manager->scope($this->dataset, $this->dimensions)->refreshMany($this->keys, Freshness::seconds($this->freshFor, $this->staleFor), $this->definition);
    }
}
