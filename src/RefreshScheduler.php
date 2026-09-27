<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Jobs\RefreshJob;
use GogoSpace\BulkCache\Support\Options;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;

final class RefreshScheduler
{
    private array $callbacks = [];

    private int $scheduledKeys = 0;

    private bool $httpRequest = false;

    public function __construct(private Container $container) {}

    public function beginRequest(): void
    {
        $this->clear();
        $this->httpRequest = true;
    }

    public function strategy(string $refresh): string
    {
        if (! in_array($refresh, ['auto', 'inline', 'defer', 'queue'], true)) {
            throw new ConfigurationException('Refresh must be auto, inline, defer or queue.');
        }
        if ($refresh === 'auto') {
            return $this->httpRequest ? 'defer' : 'inline';
        }
        if ($refresh === 'defer' && ! $this->httpRequest) {
            throw new ConfigurationException('Deferred refresh requires the bulk cache HTTP middleware. Use inline or queue in commands.');
        }
        if ($refresh === 'queue' && ! $this->container->bound('queue')) {
            throw new ConfigurationException('Queue refresh requires a configured Laravel queue.');
        }

        return $refresh;
    }

    public function schedule(string $strategy, Scope $scope, array $keys, Freshness $freshness, callable $callback, Options $options): void
    {
        $observations = $this->container->make(BulkCacheManager::class)->observations($scope->name, $options);
        if ($strategy === 'queue') {
            foreach (array_chunk($keys, $options->number('batch_size')) as $chunk) {
                $dispatch = fn () => $this->container->make('queue')->connection($options->values['queue_connection'])->push(new RefreshJob($scope->name, $scope->dimensions, $chunk, $freshness->freshFor, $freshness->staleFor, $scope->definition()), '', $options->values['queue']);
                $observations === null ? $dispatch() : $observations->measure('refresh.dispatch', $dispatch, count($chunk));
            }

            return;
        }
        if ($this->scheduledKeys + count($keys) > $options->number('max_keys')) {
            throw new ConfigurationException('Deferred refresh exceeds the per-request max_keys limit.');
        }
        $this->scheduledKeys += count($keys);
        $this->callbacks[] = $observations === null ? $callback : fn () => $observations->measure('refresh.defer', $callback, count($keys));
        $observations?->emit('refresh.schedule', 'success', count($keys));
    }

    public function finish(int $status): void
    {
        $callbacks = $this->callbacks;
        $this->clear();
        if ($status >= 400) {
            return;
        }
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $exception) {
                $this->container->make(ExceptionHandler::class)->report($exception);
            }
        }
    }

    private function clear(): void
    {
        $this->callbacks = [];
        $this->scheduledKeys = 0;
        $this->httpRequest = false;
    }
}
