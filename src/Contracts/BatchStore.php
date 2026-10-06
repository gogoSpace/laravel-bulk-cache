<?php

namespace GogoSpace\BulkCache\Contracts;

use GogoSpace\BulkCache\Support\Claim;

/** Optional bounded bulk operations; existing Store implementations remain valid. */
interface BatchStore extends Store
{
    /**
     * @param  list<string>  $keys
     * @return array<string, Claim|null>
     */
    public function claimMany(string $scope, array $keys, int $leaseMilliseconds): array;

    /**
     * @param  array<string, array{claim: Claim, payload: string, retention_milliseconds: int}>  $publications
     * @return array<string, bool>
     */
    public function publishMany(string $scope, array $publications): array;

    /** @param array<string, Claim> $claims */
    public function releaseMany(string $scope, array $claims): void;
}
