<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Contracts\BatchStore;
use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Exceptions\CleanupException;
use GogoSpace\BulkCache\Exceptions\LoaderException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Exceptions\TimeoutException;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\Envelope;
use GogoSpace\BulkCache\Support\LoadContext;
use GogoSpace\BulkCache\Support\Observations;
use GogoSpace\BulkCache\Support\Options;
use Throwable;

final class Engine
{
    public function __construct(private Clock $clock, private LoadContext $context) {}

    /** @param list<string> $keys @return array{values: array<int|string,mixed>, stale: list<string>} */
    public function read(Store $store, string $scope, array $keys, Freshness $freshness, callable $loader, Options $options, bool $allowStale, ?Observations $observations = null): array
    {
        $confirmedPublications = 0;
        $sourceFailure = null;
        try {
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
                    $stored = $this->readEnvelopes($store, $scope, array_values($chunk), $options, $observations);
                    $needed = [];
                    $states = ['hit' => 0, 'stale' => 0, 'miss' => 0];
                    foreach ($chunk as $key => $token) {
                        $envelope = $stored[$token];
                        if ($this->acceptable($envelope, $freshness, $options, $allowStale)) {
                            $selected[$key] = $envelope;
                            if ($observations !== null) {
                                $states[$envelope->state($this->clock->now(), $freshness, $options->number('negative_seconds')) === 'stale' ? 'stale' : 'hit']++;
                            }
                        } else {
                            $needed[$key] = $token;
                            if ($observations !== null) {
                                $states['miss']++;
                            }
                        }
                    }
                    if ($observations !== null) {
                        foreach ($states as $state => $count) {
                            if ($count > 0) {
                                $observations->emit('cache.result', $state, $count);
                            }
                        }
                    }
                    if ($needed !== []) {
                        $this->load($store, $scope, $needed, $freshness, $loader, $options, $allowStale, $deadline, $selected, $observations, $confirmedPublications, $sourceFailure);
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
                    $elapsed = $this->clock->monotonic() - $started;
                    $waiting += $elapsed;
                    $observations?->emit('wait', 'success', count($tokens) - count($selected), $elapsed);
                    $observations?->emit('retry', 'success', count($tokens) - count($selected));
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
        } catch (StoreException $exception) {
            if ($exception === $sourceFailure) {
                throw $exception;
            }
            throw $exception->afterCompleted($confirmedPublications);
        } catch (CleanupException $exception) {
            if ($exception === $sourceFailure) {
                throw $exception;
            }
            if ($exception->primaryFailure instanceof StoreException && $exception->primaryFailure !== $sourceFailure && $confirmedPublications > 0) {
                throw new CleanupException($exception->primaryFailure->afterCompleted($confirmedPublications), $exception->cleanupFailures);
            }
            throw $exception;
        }
    }

    private function load(Store $store, string $scope, array $needed, Freshness $freshness, callable $loader, Options $options, bool $allowStale, float $deadline, array &$selected, ?Observations $observations, int &$confirmedPublications, ?Throwable &$sourceFailure): void
    {
        $claims = [];
        $primaryFailure = null;
        try {
            if ($store instanceof BatchStore) {
                $this->checkDeadline($deadline);
                $operation = fn (): array => $store->claimMany($scope, array_values($needed), $options->number('lease_milliseconds'));
                $acquired = $observations === null ? $operation() : $observations->measure('cache.claim', $operation, count($needed));
                $complete = true;
                foreach ($needed as $key => $token) {
                    if (! array_key_exists($token, $acquired)) {
                        $complete = false;

                        continue;
                    }
                    if ($acquired[$token] !== null) {
                        $claims[$key] = $acquired[$token];
                    }
                }
                if (! $complete) {
                    throw new StoreException('The store returned incomplete claims.', phase: 'claim');
                }
            } else {
                foreach ($needed as $key => $token) {
                    $this->checkDeadline($deadline);
                    $operation = fn () => $store->claim($scope, $token, $options->number('lease_milliseconds'));
                    $claim = $observations === null ? $operation() : $observations->measure('cache.claim', $operation, 1);
                    if ($claim !== null) {
                        $claims[$key] = $claim;
                    }
                }
            }
            if ($claims === []) {
                return;
            }
            // Re-read after claiming: a preceding producer may have finished between read and claim.
            $owned = array_intersect_key($needed, $claims);
            $stored = $this->readEnvelopes($store, $scope, array_values($owned), $options, $observations);
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
            $operation = fn () => $this->context->run($scope, array_values($loading), fn () => $loader($logicalKeys));
            try {
                $loaded = $observations === null ? $operation() : $observations->measure('loader', $operation, count($loading));
            } catch (Throwable $exception) {
                $sourceFailure = $exception;
                throw $exception;
            }
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
            $publications = [];
            foreach ($loading as $key => $token) {
                $envelope = $envelopes[$key];
                $remaining = (int) floor(($observedAt + $envelope->freshFor + $envelope->staleFor - $this->clock->now()) * 1000);
                if ($remaining > 0) {
                    $publications[$token] = ['claim' => $claims[$key], 'payload' => $payloads[$key], 'retention_milliseconds' => $remaining];
                }
            }
            $this->checkDeadline($deadline);
            if ($store instanceof BatchStore && $publications !== []) {
                $operation = fn (): array => $store->publishMany($scope, $publications);
                $published = $observations === null ? $operation() : $observations->measure('cache.publish', $operation, count($publications));
                foreach ($loading as $key => $token) {
                    if (isset($publications[$token]) && ! array_key_exists($token, $published)) {
                        throw new StoreException('The store returned incomplete publication results.', phase: 'publish');
                    }
                    if ($published[$token] ?? false) {
                        $selected[$key] = $envelopes[$key];
                        $confirmedPublications++;
                    } elseif (isset($publications[$token])) {
                        $observations?->emit('publication', 'rejected', 1);
                    }
                }
            } else {
                foreach ($loading as $key => $token) {
                    $this->checkDeadline($deadline);
                    if (! isset($publications[$token])) {
                        continue;
                    }
                    $publication = $publications[$token];
                    $operation = fn (): bool => $store->publish($scope, $token, $publication['claim'], $publication['payload'], $publication['retention_milliseconds']);
                    $published = $observations === null ? $operation() : $observations->measure('cache.publish', $operation, 1);
                    if ($published) {
                        $selected[$key] = $envelopes[$key];
                        $confirmedPublications++;
                    } else {
                        $observations?->emit('publication', 'rejected', 1);
                    }
                }
            }
        } catch (Throwable $exception) {
            $primaryFailure = $exception;
            throw $exception;
        } finally {
            $cleanupFailures = [];
            if ($store instanceof BatchStore && $claims !== []) {
                $ownedClaims = [];
                foreach ($claims as $key => $claim) {
                    $ownedClaims[$needed[$key]] = $claim;
                }
                try {
                    $operation = fn () => $store->releaseMany($scope, $ownedClaims);
                    $observations === null ? $operation() : $observations->measure('cache.release', $operation, count($ownedClaims));
                } catch (CleanupException $exception) {
                    $cleanupFailures = $exception->primaryFailure === null
                        ? $exception->cleanupFailures
                        : [$exception->primaryFailure, ...$exception->cleanupFailures];
                } catch (Throwable $exception) {
                    $cleanupFailures[] = $exception;
                }
            } else {
                foreach ($claims as $key => $claim) {
                    try {
                        $operation = fn () => $store->release($scope, $needed[$key], $claim);
                        $observations === null ? $operation() : $observations->measure('cache.release', $operation, 1);
                    } catch (Throwable $exception) {
                        $cleanupFailures[] = $exception;
                    }
                }
            }
            if ($cleanupFailures !== []) {
                throw new CleanupException($primaryFailure, $cleanupFailures);
            }
        }
    }

    /** @return array<string, Envelope|null> */
    private function readEnvelopes(Store $store, string $scope, array $tokens, Options $options, ?Observations $observations): array
    {
        $operation = fn (): array => $store->readMany($scope, $tokens);
        $payloads = $observations === null ? $operation() : $observations->measure('cache.read', $operation, count($tokens));
        $envelopes = [];
        foreach ($tokens as $token) {
            if (! array_key_exists($token, $payloads)) {
                throw new StoreException('The store returned an incomplete batch.', phase: 'read', outcome: 'not_applicable');
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
