<?php

declare(strict_types=1);

use GogoSpace\BulkCache\BulkCacheManager;
use GogoSpace\BulkCache\Examples\ExampleEnvironment;
use GogoSpace\BulkCache\Examples\Maintenance\DedicatedPortableStore;
use GogoSpace\BulkCache\Examples\Maintenance\Process;
use GogoSpace\BulkCache\Examples\Maintenance\RedisInventory;

$configuration = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
require $configuration['autoload'];
require __DIR__.'/../Support/ExampleEnvironment.php';
require __DIR__.'/RedisInventory.php';
require __DIR__.'/DedicatedPortableStore.php';
require __DIR__.'/Process.php';
$application = ExampleEnvironment::boot($configuration);
$arguments = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
$barrier = $arguments['barrier'];

if ($argv[2] === 'redis-held') {
    $inventory = new RedisInventory($application['redis']->connection(), $arguments['prefix'], []);
    $claim = $inventory->store->claim($arguments['scope'], $arguments['key'], 30_000);
    if ($claim === null) {
        throw new RuntimeException('Could not hold the old producer lease.');
    }
    Process::signal($barrier.'/ready', ['generation' => $claim->generation]);
    foreach (['erased', 'recreated'] as $phase) {
        Process::awaitFile($barrier.'/'.$phase);
        $accepted = $inventory->store->publish($arguments['scope'], $arguments['key'], $claim, 'deleted-user-old-data', 300_000);
        Process::signal($barrier.'/'.$phase.'-result', ['accepted' => $accepted]);
    }
} elseif ($argv[2] === 'portable-held') {
    $store = new DedicatedPortableStore($application, $arguments['directory'], $arguments['driver']);
    $store->run(function (BulkCacheManager $manager) use ($barrier): void {
        $manager->scope('maintenance-user-v1', ['user' => 1])->rememberMany(['late'], 300, function () use ($barrier): array {
            Process::signal($barrier.'/ready', []);
            Process::awaitFile($barrier.'/finish');

            return ['late' => 'deleted-user-late-data'];
        });
    });
    Process::signal($barrier.'/finished', []);
} else {
    throw new InvalidArgumentException('Unknown maintenance action.');
}
