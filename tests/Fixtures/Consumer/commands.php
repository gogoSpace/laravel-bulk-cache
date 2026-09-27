<?php

use App\Smoke\SmokeLoader;
use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Freshness;
use GogoSpace\BulkCache\Missing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

Queue::looping(function (): void {
    file_put_contents(storage_path('app/bulk-smoke/worker-ready'), (string) getmypid());
});

$assertion = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

Artisan::command('bulk-smoke:core {store}', function () use ($assertion): void {
    config(['bulk-cache.datasets.contract' => ['store' => $this->argument('store')]]);
    $manager = app(BulkCacheManager::class);
    $scope = $manager->scope('contract', ['test' => bin2hex(random_bytes(5))]);
    $loaderCalls = [];
    $payload = ['one' => null, 'two' => false, 'three' => 0, 'four' => '', 'five' => [], 'six' => Missing::Value];
    $loader = function (array $keys) use (&$loaderCalls, $payload): array {
        $loaderCalls[] = $keys;

        return array_intersect_key($payload, array_flip($keys));
    };
    $keys = ['one', 'two', 'three', 'four', 'five', 'six', 'one'];
    $assertion($scope->rememberMany($keys, 60, $loader) === $payload, 'Values did not retain their types.');
    $assertion($scope->rememberMany($keys, 60, $loader) === $payload && count($loaderCalls) === 1, 'Cache hits invoked the loader.');
    $scope->invalidateMany(['one']);
    $scope->rememberMany($keys, 60, $loader);
    $assertion($loaderCalls[1] === ['one'], 'Invalidation loaded keys that were already fresh.');
    $scope->invalidateScope();
    $scope->rememberMany($keys, 60, $loader);
    $assertion(count($loaderCalls[2]) === 6, 'Scope invalidation did not refresh the complete scope.');
    foreach (['alice', 'bob', 'guest', 'alice'] as $subject) {
        $personal = $manager->scope('contract', ['user' => $subject, 'tenant' => 'one']);
        $result = $personal->rememberMany(['same'], 60, fn (array $keys): array => array_fill_keys($keys, $subject));
        $assertion($result === ['same' => $subject], 'Explicit user context leaked.');
    }
    $firstScope = $manager->scope('contract', ['tenant' => 'one', 'user' => 'alice']);
    $assertion($firstScope->rememberMany(['same'], 60, fn () => throw new RuntimeException('Canonical dimensions missed.')) === ['same' => 'alice'], 'Dimension order changed identity.');
    $this->line(json_encode(['passed' => true, 'store' => $this->argument('store'), 'loader_calls' => count($loaderCalls)], JSON_THROW_ON_ERROR));
});

Artisan::command('bulk-smoke:examples', function () use ($assertion): void {
    $exampleDirectory = base_path('vendor/gogospace/laravel-bulk-cache/examples');
    $quickstart = require $exampleDirectory.'/quickstart.php';
    $assertion($quickstart === ['same_values' => true, 'missing' => true, 'updated_name' => 'Updated notebook', 'loader_calls' => 2], 'Installed quickstart produced the wrong result.');
    $catalog = require $exampleDirectory.'/catalog.php';
    $assertion($catalog['public_loader_calls'] === 1 && $catalog['views']['alice'][101]['favorite'] === true && $catalog['views']['bob'][101]['favorite'] === false && $catalog['views']['guest'][101]['favorite'] === false, 'Installed catalog example did not isolate personal overlays.');
    $readModel = require $exampleDirectory.'/read-model.php';
    $assertion($readModel['source_calls'] === 1 && $readModel['same_values'] === true && $readModel['values'][10]['completed'] === 30 && $readModel['values'][20]['completed'] === 60, 'Installed read-model example did not reuse its batch result.');
    $this->line(json_encode(['passed' => true, 'examples' => ['quickstart', 'catalog', 'read-model']], JSON_THROW_ON_ERROR));
});

Artisan::command('bulk-smoke:seed {dataset} {caseName} {user=guest}', function (): void {
    $dimensions = ['case' => $this->argument('caseName'), 'tenant' => 'synthetic', 'user' => $this->argument('user')];
    $scope = BulkCache::scope($this->argument('dataset'), $dimensions);
    $scope->invalidateScope();
    $values = $scope->flexibleMany(['one'], Freshness::seconds(freshFor: 1, staleFor: 60), fn (array $keys): array => app(SmokeLoader::class)->load($keys, $dimensions), refresh: 'inline');
    $this->line(json_encode($values, JSON_THROW_ON_ERROR));
});

Artisan::command('bulk-smoke:read {dataset} {caseName} {user=guest}', function (): void {
    $dimensions = ['case' => $this->argument('caseName'), 'tenant' => 'synthetic', 'user' => $this->argument('user')];
    $values = BulkCache::scope($this->argument('dataset'), $dimensions)->flexibleMany(['one'], Freshness::seconds(freshFor: 1, staleFor: 60), refresh: 'queue');
    $this->line(json_encode($values, JSON_THROW_ON_ERROR));
});

Artisan::command('bulk-smoke:queue {caseName} {duplicates=1} {user=guest}', function () use ($assertion): void {
    $dimensions = ['case' => $this->argument('caseName'), 'tenant' => 'synthetic', 'user' => $this->argument('user')];
    BulkCache::scope('queue', $dimensions)->flexibleMany(['one'], Freshness::seconds(freshFor: 1, staleFor: 60), refresh: 'queue');
    $job = DB::table('jobs')->orderByDesc('id')->first();
    $assertion($job !== null, 'No serialized queue job was dispatched.');
    for ($duplicateNumber = 1; $duplicateNumber < (int) $this->argument('duplicates'); $duplicateNumber++) {
        $duplicate = (array) $job;
        unset($duplicate['id']);
        DB::table('jobs')->insert($duplicate);
    }
    $this->line(json_encode(['queued' => DB::table('jobs')->count()], JSON_THROW_ON_ERROR));
});

Artisan::command('bulk-smoke:jobs', function (): void {
    $this->line(json_encode(['pending' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count()], JSON_THROW_ON_ERROR));
});

Artisan::command('bulk-smoke:inline {caseName}', function () use ($assertion): void {
    $dimensions = ['case' => $this->argument('caseName'), 'tenant' => 'synthetic', 'user' => 'guest'];
    BulkCache::scope('queue', $dimensions)->flexibleMany(['one'], Freshness::seconds(freshFor: 1, staleFor: 60));
    $events = file(storage_path('app/bulk-smoke/events-'.$this->argument('caseName').'.jsonl'), FILE_IGNORE_NEW_LINES);
    $assertion(count($events) === 2, 'CLI auto did not refresh inline.');
    $this->line(json_encode(['passed' => true], JSON_THROW_ON_ERROR));
});
