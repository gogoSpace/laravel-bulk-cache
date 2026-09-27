<?php

namespace GogoSpace\BulkCache\Events;

/** Aggregate observation. Never contains keys, dimensions, values or exception text. */
final readonly class CacheEvent
{
    public function __construct(
        public string $dataset,
        public string $operation,
        public string $status,
        public int $count,
        public float $milliseconds,
    ) {}
}
