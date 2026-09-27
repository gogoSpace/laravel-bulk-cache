<?php

namespace GogoSpace\BulkCache\Support;

use Closure;
use GogoSpace\BulkCache\Events\CacheEvent;
use Throwable;

/** Listener failures are isolated from cache/source errors and never retried. */
final class Observations
{
    private int $listenerFailures = 0;

    public function __construct(private Closure $listener, private string $dataset, private Clock $clock) {}

    public function measure(string $operation, callable $callback, int $count = 0): mixed
    {
        $started = $this->clock->monotonic();
        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->emit($operation, 'error', $count, $this->clock->monotonic() - $started);
            throw $exception;
        }
        $this->emit($operation, 'success', $count, $this->clock->monotonic() - $started);

        return $result;
    }

    public function emit(string $operation, string $status, int $count = 0, float $milliseconds = 0): void
    {
        try {
            ($this->listener)(new CacheEvent($this->dataset, $operation, $status, max(0, $count), max(0, $milliseconds)));
        } catch (Throwable) {
            // Reporting a failed listener through that listener risks recursion.
            $this->listenerFailures++;
        }
    }

    public function listenerFailures(): int
    {
        return $this->listenerFailures;
    }
}
