<?php

use App\Smoke\SmokeLoader;
use GogoSpace\BulkCache\Facades\BulkCache;
use GogoSpace\BulkCache\Freshness;
use Illuminate\Support\Facades\Route;

Route::get('/bulk-smoke/{status}/{caseName}', function (int $status, string $caseName) {
    $dimensions = ['case' => $caseName, 'tenant' => 'synthetic', 'user' => request()->query('user', 'guest')];
    $values = BulkCache::scope('http', $dimensions)->flexibleMany(
        ['one'],
        Freshness::seconds(freshFor: 1, staleFor: 60),
        fn (array $keys): array => app(SmokeLoader::class)->load($keys, $dimensions),
    );

    return response()->json(['values' => $values], $status);
});

Route::get('/bulk-smoke-health', fn () => ['ready' => true]);
