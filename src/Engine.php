<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Exceptions\LoaderException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Exceptions\TimeoutException;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\Envelope;
use GogoSpace\BulkCache\Support\LoadContext;
use GogoSpace\BulkCache\Support\Options;

final class Engine
{
    public function __construct(private Clock $clock, private LoadContext $context) {}

    /** @param list<string> $keys @return array{values: array<int|string,mixed>, stale: list<string>} */
    public function read(Store $store, string $scope, array $keys, Freshness $freshness, callable $loader, Options $options, bool $allowStale): array
    {
        $tokens = [];
        foreach ($keys as $key) {
            $tokens[$key] = hash('sha256', $key);
        }
        $this->context->assertAvailable($scope, array_values($tokens));
        $deadline = $this->clock->monotonic() + $options->number('operation_milliseconds');
        $waiting = 0.0;
        $selected = [];
        do {
            $pending = array_diff_key($tokens, $selected);
            foreach (array_chunk($pending, $options->number('batch_size'), true) as $chunk) {
                $this->checkDeadline($deadline);
                $stored = $this->readEnvelopes($store, $scope, array_values($chunk), $options);
                $needed = [];
                foreach ($chunk as $key => $token) {
                    $envelope = $stored[$token];
                    if ($this->acceptable($envelope, $freshness, $options, $allowStale)) {
                        $selected[$key] = $envelope;
                    } else {
                        $needed[$key] = $token;
                    }
                }
                if ($needed !== []) {
                    $this->load($store, $scope, $needed, $freshness, $loader, $options, $allowStale, $deadline, $selected);
                }
            }
            $this->checkDeadline($deadline);
            $decisionTime = $this->clock->now();
            foreach ($selected as $key => $envelope) {
                $state = $envelope->state($decisionTime, $freshness, $options->number('negative_seconds'));
                if ($state === 'expired' || (! $allowStale && $state === 'stale')) {
                    unset($selected[$key]);
                }
            }
            if (count($selected) !== count($tokens)) {
                if ($waiting >= $options->number('wait_milliseconds')) {
                    throw new TimeoutException('Bulk cache waiting budget exhausted.');
                }
                $started = $this->clock->monotonic();
                $this->clock->sleep(min(10, max(1, (int) ($options->number('wait_milliseconds') - $waiting))));
                $waiting += $this->clock->monotonic() - $started;
            }
        } while (count($selected) !== count($tokens));

        $values = [];
        $stale = [];
        foreach ($keys as $key) {
            $envelope = $selected[$key];
            $values[$key] = $envelope->result();
            if ($envelope->state($decisionTime, $freshness, $options->number('negative_seconds')) === 'stale') {
                $stale[] = $key;
            }
        }

        return ['values' => $values, 'stale' => $stale];
    }

    private function load(Store $store, string $scope, array $needed, Freshness $freshness, callable $loader, Options $options, bool $allowStale, float $deadline, array &$selected): void
    {
        $claims = [];
        try {
            foreach ($needed as $key => $token) {
                $this->checkDeadline($deadline);
                $claim = $store->claim($scope, $token, $options->number('lease_milliseconds'));
                if ($claim !== null) {
                    $claims[$key] = $claim;
                }
            }
            if ($claims === []) {
                return;
            }
            // Re-read after claiming: a preceding producer may have finished between read and claim.
            $owned = array_intersect_key($needed, $claims);
            $stored = $this->readEnvelopes($store, $scope, array_values($owned), $options);
            $loading = [];
            foreach ($owned as $key => $token) {
                if ($this->acceptable($stored[$token], $freshness, $options, $allowStale)) {
                    $selected[$key] = $stored[$token];
                } else {
                    $loading[$key] = $token;
                }
            }
            if ($loading === []) {
                return;
            }
            $this->checkDeadline($deadline);
            $observedAt = $this->clock->now();
            $logicalKeys = array_map(strval(...), array_keys($loading));
            $loaded = $this->context->run($scope, array_values($loading), fn () => $loader($logicalKeys));
            $this->checkDeadline($deadline);
            if (! is_array($loaded) || count($loaded) !== count($loading) || array_diff_key($loaded, $loading) !== [] || array_diff_key($loading, $loaded) !== []) {
                throw new LoaderException('The loader must return exactly the requested keys. No values from this chunk were written.');
            }
            $envelopes = [];
            $payloads = [];
            foreach ($loading as $key => $token) {
                $missing = $loaded[$key] === Missing::Value;
                $envelopes[$key] = new Envelope($missing ? null : $loaded[$key], $observedAt, $missing ? min($options->number('negative_seconds'), $freshness->freshFor) : $freshness->freshFor, $missing ? 0 : $freshness->staleFor, $missing);
                $payloads[$key] = $envelopes[$key]->encode($options->number('max_payload_bytes'));
            }
            foreach ($loading as $key => $token) {
                $this->checkDeadline($deadline);
                $envelope = $envelopes[$key];
                $remaining = (int) floor(($observedAt + $envelope->freshFor + $envelope->staleFor - $this->clock->now()) * 1000);
                if ($remaining > 0 && $store->publish($scope, $token, $claims[$key], $payloads[$key], $remaining)) {
                    $selected[$key] = $envelope;
                }
            }
        } finally {
            $releaseFailure = null;
            foreach ($claims as $key => $claim) {
                try {
                    $store->release($scope, $needed[$key], $claim);
                } catch (\Throwable $exception) {
                    $releaseFailure ??= $exception;
                }
            }
            if ($releaseFailure !== null) {
                throw $releaseFailure;
            }
        }
    }

    /** @return array<string, Envelope|null> */
    private function readEnvelopes(Store $store, string $scope, array $tokens, Options $options): array
    {
        $payloads = $store->readMany($scope, $tokens);
        $envelopes = [];
        foreach ($tokens as $token) {
            if (! array_key_exists($token, $payloads)) {
                throw new StoreException('The store returned an incomplete batch.');
            }
            $envelopes[$token] = $payloads[$token] === null ? null : Envelope::decode($payloads[$token], $options->number('max_payload_bytes'));
        }

        return $envelopes;
    }

    private function acceptable(?Envelope $envelope, Freshness $freshness, Options $options, bool $allowStale): bool
    {
        $state = $envelope?->state($this->clock->now(), $freshness, $options->number('negative_seconds'));

        return $state === 'fresh' || ($allowStale && $state === 'stale');
    }

    private function checkDeadline(float $deadline): void
    {
        if ($this->clock->monotonic() >= $deadline) {
            throw new TimeoutException('Bulk cache operation budget exhausted. Configure timeouts on the underlying source as well.');
        }
    }
}
