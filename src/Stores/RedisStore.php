<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Stores;

use GogoSpace\BulkCache\Contracts\BatchStore;
use GogoSpace\BulkCache\Exceptions\CleanupException;
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
final class RedisStore implements BatchStore
{
    private const BATCH_SIZE = 100;

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
LUA, $physicalKeys, phase: 'read');

        if (! is_array($values) || count($values) !== count($keys)) {
            throw new StoreException('Redis returned an incomplete bulk read.', phase: 'read', outcome: 'not_applicable');
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
        return $this->claimMany($scope, [$key], $leaseMilliseconds)[$key];
    }

    public function claimMany(string $scope, array $keys, int $leaseMilliseconds): array
    {
        $this->validateLifetime($leaseMilliseconds);
        $claims = [];
        try {
            foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
                $owners = array_map(fn (): string => bin2hex(random_bytes(16)), $chunk);
                $physical = [$this->generationKey($scope), ...array_map(fn (string $key): string => $this->leaseKey($scope, $key), $chunk)];
                $generations = $this->evaluate(<<<'LUA'
local generation = redis.call('GET', KEYS[1])
local leases = {}
for position = 2, #KEYS do
    leases[position] = redis.call('GET', KEYS[position])
end
if not generation then
    generation = ARGV[1]
    redis.call('SET', KEYS[1], generation)
end
local result = {}
for position = 2, #KEYS do
    if leases[position] and string.sub(leases[position], 1, 33) == generation .. ':' then
        result[position - 1] = false
    else
        redis.call('SET', KEYS[position], generation .. ':' .. ARGV[position + 1], 'PX', ARGV[2])
        result[position - 1] = generation
    end
end
return result
LUA, $physical, [bin2hex(random_bytes(16)), (string) $leaseMilliseconds, ...$owners], 'claim');
                if (! is_array($generations) || count($generations) !== count($chunk)) {
                    throw new StoreException('Redis returned incomplete claims.', phase: 'claim');
                }
                foreach ($chunk as $position => $key) {
                    $generation = $generations[$position];
                    if ($generation === null || $generation === false) {
                        $claims[$key] = null;
                    } elseif (is_string($generation) && preg_match('/^[a-f0-9]{32}$/D', $generation)) {
                        $claims[$key] = new Claim($generation, $owners[$position]);
                    } else {
                        throw new StoreException('Redis returned an invalid generation.', phase: 'claim');
                    }
                }
            }
        } catch (StoreException $exception) {
            $confirmedClaims = array_filter($claims);
            $primaryFailure = $exception->afterCompleted(count($confirmedClaims));
            // Release earlier acknowledged batches. Unknown claims in the failed
            // batch cannot be reconstructed and remain bounded by lease expiry.
            try {
                $this->releaseMany($scope, $confirmedClaims);
            } catch (CleanupException $cleanupFailure) {
                throw new CleanupException($primaryFailure, $cleanupFailure->cleanupFailures);
            }
            throw $primaryFailure;
        }

        return $claims;
    }

    public function publish(string $scope, string $key, Claim $claim, string $payload, int $retentionMilliseconds): bool
    {
        return $this->publishMany($scope, [$key => ['claim' => $claim, 'payload' => $payload, 'retention_milliseconds' => $retentionMilliseconds]])[$key];
    }

    public function publishMany(string $scope, array $publications): array
    {
        // Validate every lifetime before allowing the first mutation.
        foreach ($publications as $publication) {
            $this->validateLifetime($publication['retention_milliseconds']);
        }
        $published = [];
        foreach (array_chunk($publications, self::BATCH_SIZE, true) as $chunk) {
            $physical = [$this->generationKey($scope)];
            $arguments = [];
            foreach ($chunk as $key => $publication) {
                $physical[] = $this->leaseKey($scope, (string) $key);
                $physical[] = $this->valueKey($scope, (string) $key);
                $claim = $publication['claim'];
                array_push($arguments, $claim->generation, $claim->owner, $claim->generation.':'.$claim->owner.':'.$publication['payload'], (string) $publication['retention_milliseconds']);
            }
            try {
                $results = $this->evaluate(<<<'LUA'
local generation = redis.call('GET', KEYS[1])
local result = {}
for position = 1, (#KEYS - 1) / 2 do
    local argument = (position - 1) * 4
    local leaseKey = KEYS[position * 2]
    local valueKey = KEYS[position * 2 + 1]
    local lease = redis.call('GET', leaseKey)
    local value = redis.call('GET', valueKey)
    if generation ~= ARGV[argument + 1] then
        result[position] = 0
    elseif value == ARGV[argument + 3] then
        result[position] = 1
    elseif lease ~= ARGV[argument + 1] .. ':' .. ARGV[argument + 2] then
        result[position] = 0
    else
        redis.call('SET', valueKey, ARGV[argument + 3], 'PX', ARGV[argument + 4])
        redis.call('DEL', leaseKey)
        result[position] = 1
    end
end
return result
LUA, $physical, $arguments, 'publish');
                if (! is_array($results) || count($results) !== count($chunk)) {
                    throw new StoreException('Redis returned incomplete publication results.', phase: 'publish');
                }
            } catch (StoreException $exception) {
                // Confirm exact persisted values without repeating a mutation or loader.
                // pcall lets a corrupt later item retain evidence of earlier writes.
                $confirmedCount = 0;
                try {
                    $confirmed = $this->evaluate(<<<'LUA'
local generation = redis.pcall('GET', KEYS[1])
local result = {}
for position = 1, (#KEYS - 1) / 2 do
    local argument = (position - 1) * 4
    local value = redis.pcall('GET', KEYS[position * 2 + 1])
    result[position] = generation == ARGV[argument + 1] and value == ARGV[argument + 3] and 1 or 0
end
return result
LUA, $physical, $arguments, 'read');
                    if (is_array($confirmed) && count($confirmed) === count($chunk)) {
                        $confirmedCount = count(array_filter($confirmed, fn ($value): bool => $value === 1));
                        if ($confirmedCount === count($chunk)) {
                            $published += array_fill_keys(array_keys($chunk), true);

                            continue;
                        }
                    }
                } catch (StoreException) {
                    // Keep the original uncertain mutation as the primary cause.
                }
                throw $exception->afterCompleted(count(array_filter($published)) + $confirmedCount);
            }
            foreach (array_keys($chunk) as $position => $key) {
                if ($results[$position] !== 0 && $results[$position] !== 1) {
                    throw (new StoreException('Redis returned an invalid publication result.', phase: 'publish'))->afterCompleted(count(array_filter($published)));
                }
                $published[$key] = $results[$position] === 1;
            }
        }

        return $published;
    }

    public function release(string $scope, string $key, Claim $claim): void
    {
        // A single release exposes its StoreException directly.
        $this->releaseChunk($scope, [$key => $claim]);
    }

    public function releaseMany(string $scope, array $claims): void
    {
        $failures = [];
        foreach (array_chunk($claims, self::BATCH_SIZE, true) as $chunk) {
            try {
                $this->releaseChunk($scope, $chunk);
            } catch (CleanupException $exception) {
                array_push($failures, ...$exception->cleanupFailures);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }
        if ($failures !== []) {
            throw new CleanupException(null, $failures);
        }
    }

    private function releaseChunk(string $scope, array $claims): void
    {
        $physical = [];
        $owners = [];
        foreach ($claims as $key => $claim) {
            $physical[] = $this->leaseKey($scope, (string) $key);
            $owners[] = $claim->generation.':'.$claim->owner;
        }
        $results = $this->evaluate(<<<'LUA'
local result = {}
for position = 1, #KEYS do
    local lease = redis.pcall('GET', KEYS[position])
    if type(lease) == 'table' and lease.err then
        result[position] = 0
    elseif lease == ARGV[position] then
        local removed = redis.pcall('DEL', KEYS[position])
        result[position] = type(removed) == 'number' and 1 or 0
    else
        result[position] = 1
    end
end
return result
LUA, $physical, $owners, 'release');
        if (! is_array($results) || count($results) !== count($claims)) {
            throw new StoreException('Redis returned incomplete release results.', phase: 'release');
        }
        $failures = [];
        foreach ($results as $result) {
            if ($result !== 1) {
                $failures[] = new StoreException('Redis could not release an ownership claim.', phase: 'release', outcome: 'not_applied');
            }
        }
        if (count($failures) === 1) {
            throw $failures[0];
        }
        if ($failures !== []) {
            throw new CleanupException(null, $failures);
        }
    }

    public function invalidateMany(string $scope, array $keys): void
    {
        $completedOperations = 0;
        try {
            foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
                $physical = [];
                foreach ($chunk as $key) {
                    $physical[] = $this->valueKey($scope, $key);
                    $physical[] = $this->leaseKey($scope, $key);
                }
                $this->evaluate("return redis.call('DEL', unpack(KEYS))", $physical, phase: 'invalidate');
                $completedOperations += count($chunk);
            }
        } catch (StoreException $exception) {
            throw $exception->afterCompleted($completedOperations);
        }
    }

    public function invalidateScope(string $scope): void
    {
        $this->evaluate("return redis.call('SET', KEYS[1], ARGV[1])", [$this->generationKey($scope)], [bin2hex(random_bytes(16))], 'invalidate');
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function evaluate(string $script, array $keys, array $arguments = [], string $phase = 'read'): mixed
    {
        return $this->command(['EVAL', $script, (string) count($keys), ...$keys, ...$arguments], $phase);
    }

    /** @param list<string> $command */
    private function command(array $command, string $phase): mixed
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
                throw new \RuntimeException('Redis rejected the operation: '.$error);
            }

            return $result;
        } catch (ConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StoreException('The guarded Redis operation failed.', previous: $exception, phase: $phase, outcome: match ($phase) {
                'read' => 'not_applicable',
                'connect' => 'not_applied',
                default => 'unknown',
            });
        }
    }

    private function validatePrimary(): void
    {
        $role = $this->command(['ROLE'], 'connect');
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
