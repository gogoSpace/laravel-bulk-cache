<?php

namespace GogoSpace\BulkCache\Support;

use GogoSpace\BulkCache\Exceptions\RecursiveLoadException;

final class LoadContext
{
    private array $active = [];

    public function assertAvailable(string $scope, array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($this->active[$scope.':'.$key])) {
                throw new RecursiveLoadException('A loader recursively requested an identity it is already loading.');
            }
        }
    }

    public function run(string $scope, array $keys, callable $callback): mixed
    {
        $this->assertAvailable($scope, $keys);
        foreach ($keys as $key) {
            $this->active[$scope.':'.$key] = true;
        }
        try {
            return $callback();
        } finally {
            foreach ($keys as $key) {
                unset($this->active[$scope.':'.$key]);
            }
        }
    }
}
