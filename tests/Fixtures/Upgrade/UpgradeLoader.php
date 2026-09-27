<?php

namespace App\Upgrade;

use GogoSpace\BulkCache\Contracts\Loader;
use GogoSpace\BulkCache\Engine;
use ReflectionClass;

final class UpgradeLoader implements Loader
{
    public function load(array $keys, array $dimensions): array
    {
        $stateDirectory = storage_path('app/bulk-upgrade');
        $source = json_decode((string) file_get_contents($stateDirectory.'/source.json'), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($stateDirectory.'/events.jsonl', json_encode([
            'process' => getmypid(),
            'engine_sha256' => hash_file('sha256', (new ReflectionClass(Engine::class))->getFileName()),
            'phase' => $source['phase'],
            'backend' => $dimensions['backend'],
            'user' => $dimensions['user'],
            'keys' => $keys,
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

        return array_fill_keys($keys, $source['value']);
    }
}
