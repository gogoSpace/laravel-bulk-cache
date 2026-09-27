<?php

namespace GogoSpace\BulkCache\Stores;

use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Support\Claim;
use Illuminate\Cache\Repository;
use Throwable;

/** Generation checks here are best effort, not atomic publication guards. */
final class PortableStore implements Store
{
    public function __construct(private Repository $cache, private string $prefix) {}

    public function readMany(string $scope, array $keys): array
    {
        return $this->operation('read', function () use ($scope, $keys): array {
            $generation = $this->generation($scope, false);
            if ($generation === null) {
                return array_fill_keys($keys, null);
            }
            $physical = array_map(fn (string $key): string => $this->key($scope, $generation, $key), $keys);
            $values = $this->cache->many($physical);
            $result = [];
            foreach ($keys as $index => $key) {
                if (! array_key_exists($physical[$index], $values)) {
                    throw new StoreException('The cache returned an incomplete read.', phase: 'read', outcome: 'not_applicable');
                }
                $value = $values[$physical[$index]];
                if ($value !== null && ! is_string($value)) {
                    throw new StoreException('The cache returned an invalid payload.', phase: 'read', outcome: 'not_applicable');
                }
                $result[$key] = $value;
            }

            return $result;
        });
    }

    public function claim(string $scope, string $key, int $leaseMilliseconds): Claim
    {
        return $this->operation('claim', fn (): Claim => new Claim($this->generation($scope, true), bin2hex(random_bytes(16))));
    }

    public function publish(string $scope, string $key, Claim $claim, string $payload, int $retentionMilliseconds): bool
    {
        return $this->operation('publish', function () use ($scope, $key, $claim, $payload, $retentionMilliseconds): bool {
            if ($claim->generation !== $this->generation($scope, false)) {
                return false;
            }
            if (! $this->cache->put($this->key($scope, $claim->generation, $key), $payload, max(1, (int) ceil($retentionMilliseconds / 1000)))) {
                throw new StoreException('The cache did not confirm a write.', phase: 'publish');
            }

            return true;
        });
    }

    public function release(string $scope, string $key, Claim $claim): void {}

    public function invalidateMany(string $scope, array $keys): void
    {
        $completedOperations = 0;
        try {
            $this->operation('invalidate', function () use ($scope, $keys, &$completedOperations): void {
                $generation = $this->generation($scope, false);
                if ($generation === null) {
                    return;
                }
                foreach ($keys as $key) {
                    // Laravel stores may return false when a key is already absent.
                    $physical = $this->key($scope, $generation, $key);
                    if (! $this->cache->forget($physical) && $this->cache->get($physical) !== null) {
                        throw new StoreException('The cache did not confirm removal.', phase: 'invalidate');
                    }
                    $completedOperations++;
                }
            });
        } catch (StoreException $exception) {
            throw $exception->afterCompleted($completedOperations);
        }
    }

    public function invalidateScope(string $scope): void
    {
        $this->operation('invalidate', function () use ($scope): void {
            if (! $this->cache->forever($this->generationKey($scope), bin2hex(random_bytes(16)))) {
                throw new StoreException('The cache did not confirm invalidation.', phase: 'invalidate');
            }
        });
    }

    private function generation(string $scope, bool $initialize): ?string
    {
        $key = $this->generationKey($scope);
        $generation = $this->cache->get($key);
        if ($generation === null && $initialize) {
            $candidate = bin2hex(random_bytes(16));
            $this->cache->add($key, $candidate, 31536000);
            $generation = $this->cache->get($key);
        }
        if ($generation === null && ! $initialize) {
            return null;
        }
        if (! is_string($generation) || ! preg_match('/^[a-f0-9]{32}$/D', $generation)) {
            throw new \UnexpectedValueException('The cache did not return valid scope metadata.');
        }

        return $generation;
    }

    private function operation(string $phase, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (StoreException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StoreException('The portable cache operation failed.', previous: $exception, phase: $phase, outcome: $phase === 'read' ? 'not_applicable' : 'unknown');
        }
    }

    private function generationKey(string $scope): string
    {
        return 'bulk:v1:'.hash('sha256', serialize([$this->prefix, $scope, 'generation']));
    }

    private function key(string $scope, string $generation, string $key): string
    {
        return 'bulk:v1:'.hash('sha256', serialize([$this->prefix, $scope, $generation, $key]));
    }
}
