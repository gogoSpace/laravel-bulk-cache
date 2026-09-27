<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use GogoSpace\BulkCache\Tests\Concurrency\RedisProtocolSuite;
use GogoSpace\BulkCache\Tests\Support\RedisServer;
use GogoSpace\BulkCache\Tools\Distribution;
use GogoSpace\BulkCache\Tools\Verification;
use Illuminate\Foundation\Application;
use Predis\Client;

require __DIR__.'/Support/Verification.php';
require __DIR__.'/Support/Distribution.php';

$root = dirname(__DIR__);
$evidence = Verification::evidenceDirectory($root);
Verification::require(extension_loaded('redis') && extension_loaded('pcntl') && extension_loaded('posix'), 'Installed Redis checks require PhpRedis, pcntl and posix.');
$distribution = Distribution::create($root);

if (($argv[1] ?? null) === null) {
    $smoke = Verification::json($evidence.'/consumer-result.json');
    Verification::require($smoke['commit'] === $distribution['commit'] && $smoke['archive_sha256'] === $distribution['sha256'] && count($smoke['applications']) === 2, 'Run matching composer smoke before consumer:redis.');
    $reports = [];
    foreach ($smoke['applications'] as $application) {
        $directory = $application['directory'];
        Distribution::verifyInstallation($distribution, $directory);
        $reportPath = $evidence.'/logs/redis-'.basename($directory).'.json';
        Verification::run([PHP_BINARY, __FILE__, '--consumer', $directory, $reportPath], $root, $evidence.'/logs/redis-'.basename($directory).'.log');
        $reports[] = Verification::json($reportPath);
    }
    Verification::writeJson($evidence.'/consumer-redis-result.json', ['status' => 'passed', 'commit' => $distribution['commit'], 'archive_sha256' => $distribution['sha256'], 'applications' => $reports]);
    echo "The complete Redis protocol suite passed both clients in both exact-archive Laravel consumers.\n";
    exit(0);
}
Verification::require($argv[1] === '--consumer', 'Unsupported installed Redis verification mode.');
$consumer = $argv[2];
$package = $consumer.'/vendor/gogospace/laravel-bulk-cache';
Distribution::verifyInstallation($distribution, $consumer);
require $consumer.'/vendor/autoload.php';
Verification::require(InstalledVersions::getReference('gogospace/laravel-bulk-cache') === $distribution['commit'], 'Loaded package commit differs.');
Verification::require(! InstalledVersions::isInstalled('predis/predis'), 'The portable consumer must not install optional Predis.');
// Only the optional client comes from the verification checkout. Never load its Laravel autoloader.
require $root.'/vendor/predis/predis/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $namespace = 'GogoSpace\\BulkCache\\Tests\\';
    if (str_starts_with($class, $namespace)) {
        $path = $root.'/tests/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
$classOrigins = [];
$verifyOrigins = static function () use ($consumer, $package, &$classOrigins): void {
    foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
        $filename = (new ReflectionClass($class))->getFileName();
        if (str_starts_with($class, 'GogoSpace\\BulkCache\\') && ! str_starts_with($class, 'GogoSpace\\BulkCache\\Tests\\') && ! str_starts_with($class, 'GogoSpace\\BulkCache\\Tools\\')) {
            Verification::require(is_string($filename) && str_starts_with($filename, $package.'/src/'), 'Package class leaked from another source: '.$class);
            $classOrigins[$class] = $filename;
        }
        if (str_starts_with($class, 'Illuminate\\')) {
            Verification::require(is_string($filename) && str_starts_with($filename, $consumer.'/vendor/laravel/framework/src/Illuminate/'), 'Framework class leaked from another installation: '.$class);
            $classOrigins[$class] = $filename;
        }
    }
    Verification::require(array_keys(ClassLoader::getRegisteredLoaders()) === [$consumer.'/vendor'], 'Unexpected Composer autoloader registered.');
};
$server = new RedisServer;
$scenarios = [];
try {
    foreach (['phpredis', 'predis'] as $client) {
        $scenarios[$client] = (new RedisProtocolSuite($server, $client, $package.'/config/bulk-cache.php'))->run();
    }
} finally {
    $server->stop();
}
$verifyOrigins();
Verification::require(count($scenarios['phpredis']) >= 32 && $scenarios['phpredis'] === $scenarios['predis'], 'Installed protocol suite is incomplete.');
ksort($classOrigins);
Verification::writeJson($argv[3], [
    'status' => 'passed', 'commit' => $distribution['commit'], 'archive_sha256' => $distribution['sha256'],
    'framework' => Application::VERSION, 'php' => PHP_VERSION,
    'scenarios' => $scenarios, 'scenario_executions' => array_sum(array_map(count(...), $scenarios)),
    'predis_installed_in_consumer' => InstalledVersions::isInstalled('predis/predis'),
    'predis_origin' => (new ReflectionClass(Client::class))->getFileName(),
    'verified_class_origins' => $classOrigins,
    'harness_sha256' => hash_file('sha256', $root.'/tests/Concurrency/RedisProtocolSuite.php'),
]);
echo 'Verified '.count($classOrigins)." exact-archive package/framework class origins.\n";
