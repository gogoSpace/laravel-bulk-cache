<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Examples\Maintenance;

use GogoSpace\BulkCache\Stores\RedisStore;
use GogoSpace\BulkCache\Support\Identity;
use Illuminate\Redis\Connections\Connection;
use InvalidArgumentException;
use RuntimeException;

/** Version-specific application maintenance: exact owned inventory, never SCAN or FLUSH. */
final class RedisInventory
{
    public readonly RedisStore $store;

    public readonly array $keys;

    private readonly array $scopes;

    public function __construct(private readonly Connection $connection, string $applicationPrefix, array $datasets)
    {
        // This matches the deployed v1 Redis layout, including the manager's prefix hash.
        // The example connections have no additional client key prefix.
        $client = $connection->client();
        $clientPrefix = $client instanceof \Redis ? $client->getOption(\Redis::OPT_PREFIX) : $client->getOptions()->prefix?->getPrefix();
        if ($clientPrefix !== null && $clientPrefix !== false && $clientPrefix !== '') {
            throw new InvalidArgumentException('This maintenance fixture requires an unprefixed connection; adapt and verify the inventory for your exact deployed layout.');
        }
        $prefix = 'bc:v1:'.hash('sha256', $applicationPrefix.':redis');
        $this->store = new RedisStore($connection, $prefix);
        $keys = [];
        $scopes = [];
        foreach ($datasets as $dataset) {
            $scope = Identity::scope($dataset['name'], $dataset['dimensions']);
            $scopes[] = $scope;
            $scopePrefix = $prefix.'bulk:v1:'.$scope;
            $keys[] = $scopePrefix.':generation';
            foreach ($dataset['keys'] as $key) {
                $itemPrefix = $scopePrefix.':'.hash('sha256', (string) $key);
                $keys[] = $itemPrefix.':value';
                $keys[] = $itemPrefix.':lease';
            }
        }
        $this->keys = array_values(array_unique($keys));
        $this->scopes = array_values(array_unique($scopes));
    }

    private function raw(array $command): mixed
    {
        $client = $this->connection->client();

        if ($client instanceof \Redis) {
            $client->clearLastError();
            $result = $client->rawCommand(...$command);
            $failure = $client->getLastError();
        } else {
            $failed = false;
            $result = $client->executeRaw($command, $failed);
            $failure = $failed ? (string) $result : null;
        }
        if ($failure !== null) {
            throw new RuntimeException('Redis rejected the maintenance operation: '.$failure);
        }

        return $result;
    }

    public function sample(): array
    {
        $count = 0;
        $bytes = 0;
        foreach ($this->keys as $key) {
            $size = $this->raw(['MEMORY', 'USAGE', $key]);
            if ($size !== false && $size !== null) {
                $count++;
                $bytes += (int) $size;
            }
        }

        return ['inventoried_keys' => count($this->keys), 'existing_keys' => $count, 'bytes' => $bytes];
    }

    public function revokeExistingProducers(): void
    {
        foreach ($this->scopes as $scope) {
            $this->store->invalidateScope($scope);
        }
    }

    public function purgeBatch(int $offset, int $limit): array
    {
        if ($offset < 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Use a nonnegative cursor and a batch limit from 1 to 100.');
        }
        $batch = array_slice($this->keys, $offset, $limit);
        $deleted = $batch === [] ? 0 : (int) $this->raw(['DEL', ...$batch]);

        return ['next' => $offset + count($batch), 'attempted' => count($batch), 'deleted' => $deleted, 'complete' => $offset + count($batch) >= count($this->keys)];
    }
}
