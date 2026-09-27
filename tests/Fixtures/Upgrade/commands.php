<?php

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Missing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('bulk-upgrade:exercise {operation} {phase}', function (): void {
    $operation = $this->argument('operation');
    $phase = $this->argument('phase');
    $assertion = static function (bool $condition, string $message): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    $payload = ['null' => null, 'false' => false, 'zero' => 0, 'empty' => '', 'array' => [], 'nested' => ['items' => [null, false, 0, 'value']], 'missing' => Missing::Value];
    $forbiddenLoader = static fn () => throw new RuntimeException('A persisted value was not readable after the version transition.');
    $results = [];
    if ($operation === 'queue-verify') {
        app(BulkCacheManager::class)->scheduler()->beginRequest();
    }
    foreach (['file', 'database', 'redis'] as $backend) {
        $scope = BulkCache::scope('payload-'.$backend, ['tenant' => 'one', 'user' => 'alice', 'phase' => $phase]);
        if ($operation === 'queue-incompatible') {
            config(['bulk-cache.datasets.refresh-'.$backend.'.batch_size' => 99]);
        }
        $queueScope = BulkCache::scope('refresh-'.$backend, ['tenant' => 'one', 'user' => $operation === 'queue-incompatible' ? 'bob' : 'alice', 'backend' => $backend]);
        if ($operation === 'seed') {
            $assertion($scope->rememberMany(array_keys($payload), 3600, static fn () => $payload) === $payload, 'Cannot seed persisted value types.');
            foreach (['alice', 'bob'] as $subject) {
                BulkCache::scope('isolation-'.$backend, ['user' => $subject])->rememberMany(['one', 'two'], 3600, static fn () => ['one' => $subject.'-old', 'two' => $subject.'-old']);
            }
        } elseif ($operation === 'verify') {
            $assertion($scope->rememberMany(array_keys($payload), 3600, $forbiddenLoader) === $payload, 'Persisted value types changed.');
        } elseif ($operation === 'queue-seed') {
            $queueScope->invalidateScope();
            $assertion($queueScope->flexibleMany(['one'], Freshness::seconds(1, 3600), refresh: 'inline') === ['one' => $phase.'-old'], 'Queue seed did not use the source.');
            $incompatibleScope = BulkCache::scope('refresh-'.$backend, ['tenant' => 'one', 'user' => 'bob', 'backend' => $backend]);
            $incompatibleScope->invalidateScope();
            $assertion($incompatibleScope->flexibleMany(['one'], Freshness::seconds(1, 3600), refresh: 'inline') === ['one' => $phase.'-old'], 'Cannot seed the incompatible-definition scope.');
        } elseif ($operation === 'queue') {
            foreach ([1, 2] as $duplicate) {
                $assertion($queueScope->flexibleMany(['one'], Freshness::seconds(1, 3600), refresh: 'queue') === ['one' => $phase.'-old'], 'Queued refresh did not return stale data.');
            }
        } elseif ($operation === 'queue-incompatible') {
            $assertion($queueScope->flexibleMany(['one'], Freshness::seconds(1, 3600), refresh: 'queue') === ['one' => $phase.'-old'], 'An incompatible-definition producer did not preserve its stale response.');
        } elseif ($operation === 'queue-verify') {
            // The producer's one-second freshness still permits a long stale window for inspection.
            $assertion($queueScope->flexibleMany(['one'], Freshness::seconds(3600, 3600), $forbiddenLoader, refresh: 'defer') === ['one' => $phase.'-new'], 'The other version worker did not publish its refresh.');
            $incompatibleScope = BulkCache::scope('refresh-'.$backend, ['tenant' => 'one', 'user' => 'bob', 'backend' => $backend]);
            $assertion($incompatibleScope->flexibleMany(['one'], Freshness::seconds(3600, 3600), $forbiddenLoader, refresh: 'defer') === ['one' => $phase.'-old'], 'An incompatible-definition job published into its distinct stale scope.');
        } elseif ($operation === 'invalidate') {
            BulkCache::scope('isolation-'.$backend, ['user' => 'alice'])->invalidateMany(['one']);
        } elseif ($operation === 'verify-invalidation') {
            $loadedKeys = [];
            $values = BulkCache::scope('isolation-'.$backend, ['user' => 'alice'])->rememberMany(['one', 'two'], 3600, static function (array $keys) use (&$loadedKeys): array {
                $loadedKeys = $keys;

                return array_fill_keys($keys, 'alice-new');
            });
            $assertion($values === ['one' => 'alice-new', 'two' => 'alice-old'] && $loadedKeys === ['one'], 'Rollback resurrected an invalidated entry or discarded unaffected entries.');
            $assertion(BulkCache::scope('isolation-'.$backend, ['user' => 'bob'])->rememberMany(['one', 'two'], 3600, $forbiddenLoader) === ['one' => 'bob-old', 'two' => 'bob-old'], 'Invalidation changed another user.');
            BulkCache::scope('isolation-'.$backend, ['user' => 'alice'])->invalidateScope();
            $assertion(BulkCache::scope('isolation-'.$backend, ['user' => 'alice'])->rememberMany(['one', 'two'], 3600, static fn () => ['one' => 'scope-new', 'two' => 'scope-new']) === ['one' => 'scope-new', 'two' => 'scope-new'], 'Scope invalidation stopped working after rollback.');
        } else {
            throw new RuntimeException('Unknown upgrade verification operation: '.$operation);
        }
        $results[] = $backend;
    }
    if ($operation === 'queue-verify') {
        app(BulkCacheManager::class)->scheduler()->finish(500);
    }
    $this->line(json_encode(['operation' => $operation, 'phase' => $phase, 'backends' => $results, 'pending' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count(), 'definition_rejections' => DB::table('failed_jobs')->where('exception', 'like', '%The registered dataset changed after this refresh was queued.%')->count()], JSON_THROW_ON_ERROR));
});
