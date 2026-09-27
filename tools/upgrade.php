<?php

declare(strict_types=1);

use GogoSpace\BulkCache\Tools\Distribution;
use GogoSpace\BulkCache\Tools\Verification;

require __DIR__.'/Support/Verification.php';
require __DIR__.'/Support/Distribution.php';

$rootDirectory = dirname(__DIR__);
Verification::require(PHP_VERSION_ID >= 80400 && extension_loaded('redis') && extension_loaded('pdo_sqlite'), 'Upgrade checks require PHP 8.4+, PhpRedis, SQLite PDO and redis-server; missing prerequisites are failures.');
$distribution = Distribution::create($rootDirectory);
$evidenceDirectory = Verification::evidenceDirectory($rootDirectory);
$upgradeDirectory = Verification::directory($evidenceDirectory.'/upgrade');
$betaCommit = 'e97a068dcabac97ee93704264f19c7fccc611fee';
$betaRepository = $rootDirectory.'/research/execution/publication/repository';
if (! is_dir($betaRepository.'/.git')) {
    $betaRepository = $upgradeDirectory.'/beta-source.git';
    if (! is_dir($betaRepository)) {
        Verification::run(['git', 'clone', '--bare', 'https://github.com/gogoSpace/laravel-bulk-cache.git', $betaRepository], $rootDirectory, $upgradeDirectory.'/beta-clone.log');
    }
}
$resolvedBeta = trim(Verification::run(['git', 'rev-parse', 'v0.1.0-beta.1^{commit}'], $betaRepository, $upgradeDirectory.'/beta-reference.log'));
Verification::require($resolvedBeta === $betaCommit, 'The beta.1 tag no longer resolves to the reviewed public release.');
$betaDirectory = Verification::directory($upgradeDirectory.'/beta-distribution');
$betaArchive = $betaDirectory.'/laravel-bulk-cache-'.$betaCommit.'.zip';
Verification::run(['git', 'archive', '--format=zip', '--output='.$betaArchive, $betaCommit], $betaRepository, $upgradeDirectory.'/beta-archive.log');
$betaDistribution = Distribution::inspect($betaRepository, $betaDirectory, $betaCommit, $betaArchive, '0.1.0-beta.1', false);
$redisPort = Verification::availablePort();
$redisDirectory = Verification::directory($upgradeDirectory.'/redis-'.bin2hex(random_bytes(6)));
$redisProcess = Verification::start(['redis-server', '--bind', '127.0.0.1', '--port', (string) $redisPort, '--save', '', '--appendonly', 'no', '--dir', $redisDirectory], $rootDirectory, $redisDirectory.'/server.log');
$report = ['commit' => $distribution['commit'], 'archive_sha256' => $distribution['sha256'], 'beta_commit' => $betaCommit, 'beta_archive_sha256' => $betaDistribution['sha256'], 'php' => PHP_VERSION, 'applications' => []];
try {
    Verification::await(static function () use ($redisPort): bool {
        try {
            $connection = new Redis;
            $connection->connect('127.0.0.1', $redisPort, 0.1);
            $ready = $connection->ping();
            $connection->close();

            return (bool) $ready;
        } catch (Throwable) {
            return false;
        }
    }, 'The isolated upgrade Redis did not start.');
    foreach ([12, 13] as $majorVersion) {
        $consumerDirectory = $upgradeDirectory.'/laravel-'.$majorVersion;
        if (! is_file($consumerDirectory.'/artisan')) {
            Verification::run(['composer', 'create-project', 'laravel/laravel', $consumerDirectory, $majorVersion.'.*', '--no-dev', '--prefer-dist', '--no-interaction'], $rootDirectory, $upgradeDirectory.'/create-'.$majorVersion.'.log');
        }
        // Only this test owns these consumers. Source, database, cache and jobs survive both package replacements.
        Distribution::install($betaDistribution, $consumerDirectory);
        $artisan = static function (array $arguments) use ($consumerDirectory): string {
            return Verification::run([PHP_BINARY, 'artisan', ...$arguments, '--no-ansi', '--no-interaction'], $consumerDirectory, $consumerDirectory.'/command-'.str_replace(':', '-', $arguments[0]).'.log');
        };
        $artisan(['config:clear']);
        $stateDirectory = Verification::directory($consumerDirectory.'/storage/app/bulk-upgrade');
        file_put_contents($stateDirectory.'/events.jsonl', '');
        Verification::directory($consumerDirectory.'/app/Upgrade');
        copy($rootDirectory.'/tests/Fixtures/Upgrade/UpgradeLoader.php', $consumerDirectory.'/app/Upgrade/UpgradeLoader.php');
        copy($rootDirectory.'/tests/Fixtures/Upgrade/commands.php', $consumerDirectory.'/routes/console.php');
        $environmentPath = $consumerDirectory.'/.env';
        $environment = (string) file_get_contents($environmentPath);
        foreach (['APP_ENV' => 'testing', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $consumerDirectory.'/database/database.sqlite', 'REDIS_CLIENT' => 'phpredis', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => $redisPort] as $key => $value) {
            $line = $key.'='.$value;
            $environment = preg_match('/^'.preg_quote($key, '/').'=/m', $environment) ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $environment) : $environment."\n".$line;
        }
        file_put_contents($environmentPath, $environment."\n");
        $prefix = 'version-transition-'.bin2hex(random_bytes(12));
        file_put_contents($consumerDirectory.'/config/bulk-cache.php', '<?php'."\n".'$configuration = require dirname(__DIR__).\'/vendor/gogospace/laravel-bulk-cache/config/bulk-cache.php\';'."\n".'$configuration[\'prefix\'] = '.var_export($prefix, true).';'."\n".<<<'PHP_CONFIGURATION'
$configuration['negative_seconds'] = 3600;
$configuration['queue_connection'] = 'database';
$configuration['queue'] = 'bulk-upgrade';
foreach (['file', 'database', 'redis'] as $backend) {
    $options = ['driver' => $backend === 'redis' ? 'redis' : 'portable', 'store' => $backend === 'redis' ? 'file' : $backend];
    $configuration['datasets']['payload-'.$backend] = $options;
    $configuration['datasets']['isolation-'.$backend] = $options;
    $configuration['datasets']['refresh-'.$backend] = $options + ['loader' => App\Upgrade\UpgradeLoader::class];
}
return $configuration;
PHP_CONFIGURATION);
        Verification::run(['composer', 'dump-autoload', '--no-dev', '--optimize', '--no-interaction'], $consumerDirectory, $consumerDirectory.'/autoload.log');
        $artisan(['migrate:fresh', '--force']);
        $artisan(['config:cache']);
        $exercise = static function (string $operation, string $phase) use ($artisan): array {
            return json_decode(trim($artisan(['bulk-upgrade:exercise', $operation, $phase])), true, flags: JSON_THROW_ON_ERROR);
        };
        $events = static fn (): array => array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($stateDirectory.'/events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $queue = static function (string $phase) use ($stateDirectory, $exercise): void {
            Verification::writeJson($stateDirectory.'/source.json', ['phase' => $phase, 'value' => $phase.'-old']);
            $exercise('queue-seed', $phase);
            usleep(1100000);
            Verification::writeJson($stateDirectory.'/source.json', ['phase' => $phase, 'value' => $phase.'-new']);
            $state = $exercise('queue', $phase);
            Verification::require($state['pending'] === 6 && $state['failed'] === 0, 'All three backends must produce real duplicate serialized refresh jobs before changing code.');
            $state = $exercise('queue-incompatible', $phase);
            Verification::require($state['pending'] === 9, 'Each backend must queue an actual incompatible-definition job.');
        };
        $work = static function (string $phase, array $expectedDistribution) use ($artisan, $exercise, $events, $consumerDirectory): array {
            $beforeEvents = $events();
            $artisan(['queue:work', 'database', '--queue=bulk-upgrade', '--max-jobs=15', '--max-time=20', '--sleep=1', '--tries=1', '--timeout=15']);
            $state = $exercise('queue-verify', $phase);
            $workerEvents = array_slice($events(), count($beforeEvents));
            Verification::require($state['pending'] === 0 && $state['failed'] === 3 && $state['definition_rejections'] === 3, 'Compatible jobs must succeed and all incompatible definitions must fail explicitly.');
            Verification::require(count($workerEvents) === 3 && count(array_unique(array_column($workerEvents, 'process'))) === 1, 'One real worker must load each backend once and suppress duplicate refreshes.');
            Verification::require(array_unique(array_column($workerEvents, 'user')) === ['alice'], 'An incompatible-definition worker invoked the loader.');
            $expectedEngine = hash_file('sha256', $consumerDirectory.'/vendor/gogospace/laravel-bulk-cache/src/Engine.php');
            Verification::require(array_unique(array_column($workerEvents, 'engine_sha256')) === [$expectedEngine], 'Worker executed a different package source.');
            Verification::require(array_intersect(array_column($beforeEvents, 'process'), array_column($workerEvents, 'process')) === [], 'Worker must run in a separate process from producers.');

            $artisan(['queue:flush']);

            return ['worker_commit' => $expectedDistribution['commit'], 'engine_sha256' => $expectedEngine, 'loaded_backends' => array_column($workerEvents, 'backend'), 'compatible_jobs' => 6, 'rejected_definition_jobs' => 3, 'loader_calls' => 3];
        };

        $exercise('seed', 'beta');
        $queue('upgrade');
        Distribution::install($distribution, $consumerDirectory);
        $artisan(['config:clear']);
        $artisan(['config:cache']);
        $exercise('verify', 'beta');
        $upgradeWorker = $work('upgrade', $distribution);
        $exercise('seed', 'candidate');
        $exercise('invalidate', 'candidate');
        $queue('rollback');
        Distribution::install($betaDistribution, $consumerDirectory);
        $artisan(['config:clear']);
        $artisan(['config:cache']);
        $exercise('verify', 'candidate');
        $exercise('verify-invalidation', 'candidate');
        $rollbackWorker = $work('rollback', $betaDistribution);
        $installedPackages = Verification::json($consumerDirectory.'/vendor/composer/installed.json')['packages'];
        $framework = array_values(array_filter($installedPackages, static fn (array $package): bool => $package['name'] === 'laravel/framework'))[0];
        Verification::require(preg_match('/^v?'.$majorVersion.'\\./', $framework['version']) === 1, 'The version-transition consumer installed the wrong Laravel major version.');
        $report['applications'][] = ['framework' => $framework['version'], 'laravel' => $majorVersion, 'retained_backends' => ['file', 'database-sqlite', 'redis-phpredis'], 'stored_value_types' => ['null', 'false', 'zero', 'empty-string', 'empty-array', 'nested-array', 'missing'], 'upgrade_worker' => $upgradeWorker, 'rollback_worker' => $rollbackWorker, 'invalidation_and_user_isolation' => true, 'configuration_cached' => true, 'no_dev_install' => true];
        echo 'Laravel '.$majorVersion." beta.1 upgrade and rollback passed with retained cache data and real cross-version jobs.\n";
    }
} finally {
    Verification::stop($redisProcess);
}
$report['status'] = 'passed';
Verification::writeJson($evidenceDirectory.'/upgrade-result.json', $report);
