<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/Verification.php';
require __DIR__.'/Support/RedisBenchmark.php';

use GogoSpace\BulkCache\Tools\RedisBenchmark;
use GogoSpace\BulkCache\Tools\Verification;

$report = (new RedisBenchmark)->run();
$path = $argv[1] ?? dirname(__DIR__).'/research/execution/next-beta/logs/redis-benchmark.json';
Verification::writeJson($path, $report);
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
