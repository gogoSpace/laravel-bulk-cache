<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Stores;

use GogoSpace\BulkCache\Contracts\Store;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Exceptions\StoreException;
use GogoSpace\BulkCache\Support\Claim;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Predis\Client;
use Predis\Command\Processor\KeyPrefixProcessor;
use Predis\Connection\NodeConnectionInterface;
use Throwable;

/**
 * An internal protocol for one authoritative Redis primary.
 *
 * Every script uses raw strings. Client serialization, compression and key
 * prefix settings are never changed on the shared Laravel connection.
 */
final class RedisStore implements Store
{
    private readonly string $prefix;

    public function __construct(private readonly Connection $connection, string $prefix)
    {
        $client = $connection->client();

        if ($connection instanceof PhpRedisConnection && $client instanceof \Redis) {
            $connectionPrefix = $client->getOption(\Redis::OPT_PREFIX);
            $this->prefix = (is_string($connectionPrefix) ? $connectionPrefix : '').$prefix;
            $this->validatePrimary();

            return;
        }

        if ($connection instanceof PredisConnection && $client instanceof Client) {
            $transport = $client->getConnection();

            if (! $transport instanceof NodeConnectionInterface) {
                throw new ConfigurationException('Guarded Redis requires one primary connection; clusters, replication and sharding are unsupported.');
            }

            $prefixProcessor = $client->getOptions()->prefix;
            if ($prefixProcessor !== null && ! $prefixProcessor instanceof KeyPrefixProcessor) {
                throw new ConfigurationException('Guarded Redis requires a standard Predis key prefix processor.');
            }
            $this->prefix = ($prefixProcessor?->getPrefix() ?? '').$prefix;
            $this->validatePrimary();

            return;
        }

        throw new ConfigurationException('Guarded Redis requires a single-primary PhpRedis or Predis connection.');
    }

    /** @param list<string> $keys
     * @return array<string, string|null>
     */
    public function readMany(string $scope, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $physicalKeys = [$this->generationKey($scope)];

        foreach ($keys as $key) {
            $physicalKeys[] = $this->valueKey($scope, $key);
        }

        $values = $this->evaluate(<<<'LUA'
local generation = redis.call('GET', KEYS[1])
local values = {}
for position = 2, #KEYS do
    local value = redis.call('GET', KEYS[position])
    if generation and value and string.sub(value, 1, 33) == generation .. ':' then
        values[position - 1] = string.sub(value, 67)
    else
        values[position - 1] = false
    end
end
return values
LUA, $physicalKeys);

        if (! is_array($values) || count($values) !== count($keys)) {
            throw new StoreException('Redis returned an incomplete bulk read.');
        }

        $result = [];

        foreach ($keys as $position => $key) {
            $value = $values[$position];
            $result[$key] = is_string($value) ? $value : null;
        }

        return $result;
    }

    public function claim(string $scope, string $key, int $leaseMilliseconds): ?Claim
    {
        $this->validateLifetime($leaseMilliseconds);
        $owner = bin2hex(random_bytes(16));
        $generation = $this->evaluate(<<<'LUA'
local generation = redis.call('GET', KEYS[1])
local lease = redis.call('GET', KEYS[2])
if generation and lease and string.sub(lease, 1, 33) == generation .. ':' then
    return false
end
if not generation then
    generation = ARGV[1]
    redis.call('SET', KEYS[1], generation)
end
redis.call('SET', KEYS[2], generation .. ':' .. ARGV[2], 'PX', ARGV[3])
return generation
LUA, [$this->generationKey($scope), $this->leaseKey($scope, $key)], [bin2hex(random_bytes(16)), $owner, (string) $leaseMilliseconds]);

        if ($generation === null || $generation === false) {
            return null;
        }

        if (! is_string($generation) || ! preg_match('/^[a-f0-9]{32}$/D', $generation)) {
            throw new StoreException('Redis returned an invalid generation.');
        }

        return new Claim($generation, $owner);
    }

    public function publish(string $scope, string $key, Claim $claim, string $payload, int $retentionMilliseconds): bool
    {
        $this->validateLifetime($retentionMilliseconds);
        $keys = [$this->generationKey($scope), $this->leaseKey($scope, $key), $this->valueKey($scope, $key)];
        $publication = $claim->generation.':'.$claim->owner.':'.$payload;

        try {
            return $this->evaluate(<<<'LUA'
local generation = redis.call('GET', KEYS[1])
local lease = redis.call('GET', KEYS[2])
local value = redis.call('GET', KEYS[3])
if generation ~= ARGV[1] then
    return 0
end
if value == ARGV[3] then
    return 1
end
if lease ~= ARGV[1] .. ':' .. ARGV[2] then
    return 0
end
redis.call('SET', KEYS[3], ARGV[3], 'PX', ARGV[4])
redis.call('DEL', KEYS[2])
return 1
LUA, $keys, [$claim->generation, $claim->owner, $publication, (string) $retentionMilliseconds]) === 1;
        } catch (StoreException $exception) {
            // Read-only confirmation can identify a committed write whose reply
            // was lost. Never repeat the mutation or rerun the loader here.
            try {
                $confirmed = $this->evaluate(<<<'LUA'
local generation = redis.call('GET', KEYS[1])
local value = redis.call('GET', KEYS[2])
return generation == ARGV[1] and value == ARGV[2] and 1 or 0
LUA, [$keys[0], $keys[2]], [$claim->generation, $publication]);

                if ($confirmed === 1) {
                    return true;
                }
            } catch (StoreException) {
                // The original failure describes the uncertain publication.
            }

            throw $exception;
        }
    }

    public function release(string $scope, string $key, Claim $claim): void
    {
        $this->evaluate(<<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA, [$this->leaseKey($scope, $key)], [$claim->generation.':'.$claim->owner]);
    }

    /** @param list<string> $keys */
    public function invalidateMany(string $scope, array $keys): void
    {
        foreach ($keys as $key) {
            $this->evaluate("return redis.call('DEL', KEYS[1], KEYS[2])", [$this->valueKey($scope, $key), $this->leaseKey($scope, $key)]);
        }
    }

    public function invalidateScope(string $scope): void
    {
        $this->evaluate("return redis.call('SET', KEYS[1], ARGV[1])", [$this->generationKey($scope)], [bin2hex(random_bytes(16))]);
    }

    /** @param list<string> $keys
     * @param  list<string>  $arguments
     */
    private function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        return $this->command(['EVAL', $script, (string) count($keys), ...$keys, ...$arguments]);
    }

    /** @param list<string> $command */
    private function command(array $command): mixed
    {

        try {
            $client = $this->connection->client();
            if ($client instanceof \Redis) {
                $client->clearLastError();
                $result = $client->rawCommand(...$command);
                $error = $client->getLastError();
            } elseif ($client instanceof Client) {
                $failed = false;
                $result = $client->executeRaw($command, $failed);
                $error = $failed ? (string) $result : null;
            } else {
                throw new ConfigurationException('The guarded Redis client changed to an unsupported connection.');
            }

            if ($error !== null) {
                throw new StoreException('Redis rejected the guarded operation: '.$error);
            }

            return $result;
        } catch (Throwable $exception) {
            throw new StoreException('The guarded Redis operation failed; its mutation may have been applied.', previous: $exception);
        }
    }

    private function validatePrimary(): void
    {
        $role = $this->command(['ROLE']);
        if (! is_array($role) || ($role[0] ?? null) !== 'master') {
            throw new ConfigurationException('Guarded Redis requires an authoritative primary; a replica cannot serve guarded reads.');
        }
    }

    private function generationKey(string $scope): string
    {
        return $this->prefix.'bulk:v1:'.$scope.':generation';
    }

    private function valueKey(string $scope, string $key): string
    {
        return $this->prefix.'bulk:v1:'.$scope.':'.$key.':value';
    }

    private function leaseKey(string $scope, string $key): string
    {
        return $this->prefix.'bulk:v1:'.$scope.':'.$key.':lease';
    }

    private function validateLifetime(int $milliseconds): void
    {
        if ($milliseconds <= 0 || $milliseconds > 31_536_000_000) {
            throw new ConfigurationException('Redis lifetimes must be between 1 millisecond and one year.');
        }
    }
}
