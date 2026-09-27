<?php

declare(strict_types=1);

use GogoSpace\BulkCache\Tools\Distribution;
use GogoSpace\BulkCache\Tools\Verification;

require __DIR__.'/Support/Verification.php';
require __DIR__.'/Support/Distribution.php';

$rootDirectory = dirname(__DIR__);
$distribution = Distribution::create($rootDirectory);
$consumerDirectory = Verification::directory(Verification::evidenceDirectory($rootDirectory).'/distribution/minimal-consumer');
Verification::writeJson($consumerDirectory.'/composer.json', ['name' => 'bulk-cache-verification/minimal-consumer', 'type' => 'project', 'license' => 'MIT', 'require' => ['php' => '^8.3'], 'config' => ['allow-plugins' => false]]);
Distribution::install($distribution, $consumerDirectory);
Verification::run(['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'], $consumerDirectory, $consumerDirectory.'/production-install.log');
Verification::run([...Verification::portablePhp($rootDirectory), '-r', <<<'PHP'
require 'vendor/autoload.php';
if (! class_exists(GogoSpace\BulkCache\BulkCacheManager::class)
    || ! class_exists(GogoSpace\BulkCache\BulkCacheServiceProvider::class)
    || ! class_exists(GogoSpace\BulkCache\Console\DiagnoseCommand::class)
    || class_exists('Redis') || class_exists('Predis\Client')
    || class_exists('Orchestra\Testbench\TestCase') || class_exists('PHPUnit\Framework\TestCase')) {
    throw new RuntimeException('Production autoload requires a development or optional dependency.');
}
PHP], $consumerDirectory, $consumerDirectory.'/autoload-check.log');
unset($distribution['repository']);
Verification::writeJson(Verification::evidenceDirectory($rootDirectory).'/package-result.json', $distribution + ['no_dev_install' => true, 'optional_clients_loaded' => false]);
echo 'MIT distribution '.$distribution['commit'].' passed: '.$distribution['file_count']." files, extracted no-dev install.\n";
echo 'Local Composer repository: '.$distribution['repository_url']."\n";
