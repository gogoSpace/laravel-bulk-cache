<?php

declare(strict_types=1);

namespace App\Smoke;

use GogoSpace\BulkCache\Contracts\Loader;
use RuntimeException;

final class SmokeLoader implements Loader
{
    public function load(array $keys, array $dimensions): array
    {
        $caseName = $dimensions['case'];
        $sourcePath = storage_path('app/bulk-smoke/source-'.$caseName.'.json');
        $source = json_decode((string) file_get_contents($sourcePath), true, flags: JSON_THROW_ON_ERROR);
        $value = $source['values'][$dimensions['user']] ?? $source['value'];
        $event = ['process' => getmypid(), 'keys' => $keys, 'dimensions' => $dimensions, 'value' => $value];
        file_put_contents(storage_path('app/bulk-smoke/events-'.$caseName.'.jsonl'), json_encode($event, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        if (($source['failures'] ?? 0) > 0) {
            $source['failures']--;
            file_put_contents($sourcePath, json_encode($source, JSON_THROW_ON_ERROR), LOCK_EX);
            throw new RuntimeException('Synthetic upstream failure for worker retry verification.');
        }

        return array_fill_keys($keys, $value);
    }
}
