<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use GogoSpace\BulkCache\Tests\Concurrency\RedisProtocolSuite;
use GogoSpace\BulkCache\Tests\Support\RedisServer;

foreach (['redis', 'pcntl', 'posix'] as $extension) {
    if (! extension_loaded($extension)) {
        fwrite(STDERR, "The concurrency suite requires the {$extension} extension.\n");
        exit(1);
    }
}

$server = new RedisServer;

try {
    foreach (['phpredis', 'predis'] as $client) {
        (new RedisProtocolSuite($server, $client))->run();
    }
} finally {
    $server->stop();
}
