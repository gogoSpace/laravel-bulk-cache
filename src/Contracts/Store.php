<?php

namespace GogoSpace\BulkCache\Contracts;

use GogoSpace\BulkCache\Support\Claim;

interface Store
{
    /** @param list<string> $keys @return array<string, string|null> */
    public function readMany(string $scope, array $keys): array;

    public function claim(string $scope, string $key, int $leaseMilliseconds): ?Claim;

    public function publish(string $scope, string $key, Claim $claim, string $payload, int $retentionMilliseconds): bool;

    public function release(string $scope, string $key, Claim $claim): void;

    /** @param list<string> $keys */
    public function invalidateMany(string $scope, array $keys): void;

    public function invalidateScope(string $scope): void;
}
