<?php

namespace GogoSpace\BulkCache\Facades;

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Scope;
use Illuminate\Support\Facades\Facade;

/** @method static Scope scope(string $name, array $dimensions = []) */
final class BulkCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BulkCacheManager::class;
    }
}
