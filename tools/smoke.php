<?php

declare(strict_types=1);

use GogoSpace\BulkCache\Tools\Distribution;
use GogoSpace\BulkCache\Tools\Verification;

require __DIR__.'/Support/Verification.php';
require __DIR__.'/Support/Distribution.php';

$rootDirectory = dirname(__DIR__);
$distribution = Distribution::create($rootDirectory);
$consumerRoot = Verification::directory($rootDirectory.'/research/execution/consumers');
$report = ['commit' => $distribution['commit'], 'archive_sha256' => $distribution['sha256'], 'php' => PHP_VERSION, 'applications' => []];
$defaultPrefixes = [];

foreach ([12, 13] as $majorVersion) {
    $consumerDirectory = $consumerRoot.'/laravel-'.$majorVersion;
    if (! is_file($consumerDirectory.'/artisan')) {
        Verification::run(['composer', 'create-project', 'laravel/laravel', $consumerDirectory, $majorVersion.'.*', '--no-dev', '--prefer-dist', '--no-interaction'], $rootDirectory, $consumerRoot.'/create-'.$majorVersion.'.log');
    }
    Distribution::install($distribution, $consumerDirectory);
    $artisan = static function (array $arguments) use ($consumerDirectory): string {
        return Verification::run([PHP_BINARY, '-n', 'artisan', ...$arguments, '--no-ansi', '--no-interaction'], $consumerDirectory, $consumerDirectory.'/command-'.str_replace(':', '-', $arguments[0]).'.log');
    };
    $artisan(['config:clear']);
    $artisan(['vendor:publish', '--provider=GogoSpace\BulkCache\BulkCacheServiceProvider', '--force']);
    Verification::require(is_file($consumerDirectory.'/config/bulk-cache.php'), 'Provider did not publish the package configuration.');
    $defaultPrefixes[] = trim(Verification::run([PHP_BINARY, '-n', '-r', <<<'PHP'
require 'vendor/autoload.php';
$application = require 'bootstrap/app.php';
$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$prefix = config('bulk-cache.prefix');
$expected = 'application:'.hash('sha256', serialize([env('APP_NAME', 'laravel'), env('APP_ENV', 'production'), env('APP_KEY')]));
if ($prefix !== $expected) {
    throw new RuntimeException('Default namespace does not include this application identity.');
}
echo $prefix;
PHP], $consumerDirectory, $consumerDirectory.'/default-prefix.log'));
    Verification::directory($consumerDirectory.'/app/Smoke');
    $stateDirectory = Verification::directory($consumerDirectory.'/storage/app/bulk-smoke');
    foreach (['SmokeLoader', 'LegacyKernel'] as $fixtureClass) {
        copy($rootDirectory.'/tests/Fixtures/Consumer/'.$fixtureClass.'.php', $consumerDirectory.'/app/Smoke/'.$fixtureClass.'.php');
    }
    copy($rootDirectory.'/tests/Fixtures/Consumer/routes.php', $consumerDirectory.'/routes/web.php');
    copy($rootDirectory.'/tests/Fixtures/Consumer/commands.php', $consumerDirectory.'/routes/console.php');
    file_put_contents($consumerDirectory.'/config/bulk-cache.php', <<<'PHP'
<?php

$configuration = require dirname(__DIR__).'/vendor/gogospace/laravel-bulk-cache/config/bulk-cache.php';
$configuration['prefix'] = 'bulk-smoke-'.basename(dirname(__DIR__));
$configuration['store'] = 'file';
$configuration['queue_connection'] = 'database';
$configuration['datasets'] = [
    'http' => ['store' => 'file', 'loader' => App\Smoke\SmokeLoader::class],
    'queue' => ['store' => 'file', 'loader' => App\Smoke\SmokeLoader::class],
];

return $configuration;
PHP);
    $environmentPath = $consumerDirectory.'/.env';
    $environment = (string) file_get_contents($environmentPath);
    foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'database', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $consumerDirectory.'/database/database.sqlite'] as $key => $value) {
        $line = $key.'='.$value;
        $environment = preg_match('/^'.preg_quote($key, '/').'=/m', $environment) ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $environment) : $environment."\n".$line;
    }
    file_put_contents($environmentPath, $environment."\n");
    Verification::run(['composer', 'dump-autoload', '--no-dev', '--optimize', '--no-interaction'], $consumerDirectory, $consumerDirectory.'/autoload.log');
    $artisan(['migrate:fresh', '--force']);
    $artisan(['config:cache']);
    Verification::run([PHP_BINARY, '-n', '-r', "require 'vendor/autoload.php'; if (class_exists('Redis') || class_exists('Predis\\\\Client') || class_exists('PHPUnit\\\\Framework\\\\TestCase') || class_exists('Orchestra\\\\Testbench\\\\TestCase')) { exit(1); }"], $consumerDirectory, $consumerDirectory.'/optional-dependencies.log');
    foreach (['array', 'file', 'database'] as $storeName) {
        $artisan(['bulk-smoke:core', $storeName]);
    }
    $artisan(['bulk-smoke:examples']);
    $source = static function (string $caseName, string $value, int $failures = 0) use ($stateDirectory): void {
        Verification::writeJson($stateDirectory.'/source-'.$caseName.'.json', ['value' => $value, 'failures' => $failures]);
    };
    $events = static function (string $caseName) use ($stateDirectory): array {
        $path = $stateDirectory.'/events-'.$caseName.'.jsonl';

        return is_file($path) ? array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    };
    $seed = static function (string $dataset, string $caseName, string $user = 'guest') use ($stateDirectory, $source, $artisan): void {
        file_put_contents($stateDirectory.'/events-'.$caseName.'.jsonl', '');
        $source($caseName, 'old');
        $artisan(['bulk-smoke:seed', $dataset, $caseName, $user]);
        $source($caseName, 'new');
    };

    foreach (['modern', 'legacy'] as $kernelMode) {
        $routerPath = $consumerDirectory.'/bulk-smoke-router.php';
        file_put_contents($routerPath, "<?php\nrequire __DIR__.'/vendor/autoload.php';\n\$application = require __DIR__.'/bootstrap/app.php';\n".($kernelMode === 'legacy' ? "\$application->singleton(Illuminate\\Contracts\\Http\\Kernel::class, App\\Smoke\\LegacyKernel::class);\n" : '')."\$application->handleRequest(Illuminate\\Http\\Request::capture());\n");
        $port = Verification::availablePort();
        $server = Verification::start([PHP_BINARY, '-n', '-S', '127.0.0.1:'.$port, $routerPath], $consumerDirectory, $consumerDirectory.'/http-'.$kernelMode.'.log');
        try {
            Verification::await(static function () use ($port): bool {
                $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorNumber, $errorMessage, 0.1);
                if (is_resource($socket)) {
                    fclose($socket);

                    return true;
                }

                return false;
            }, 'HTTP server did not become ready.');
            foreach ([200, 302, 404, 500] as $statusCode) {
                $caseName = $kernelMode.'-'.$statusCode;
                $seed('http', $caseName);
                usleep(1100000);
                $response = Verification::request('http://127.0.0.1:'.$port.'/bulk-smoke/'.$statusCode.'/'.$caseName);
                Verification::require($response['status'] === $statusCode, 'HTTP response status changed: '.$response['body']);
                $payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
                Verification::require($payload['values']['one'] === 'old', 'HTTP stale response did not return its cached value.');
                Verification::request('http://127.0.0.1:'.$port.'/bulk-smoke-health');
                $caseEvents = $events($caseName);
                Verification::require(count($caseEvents) === ($statusCode < 400 ? 2 : 1), $kernelMode.' HTTP '.$statusCode.' executed the wrong number of refreshes.');
                if ($statusCode < 400) {
                    Verification::require($caseEvents[1]['value'] === 'new' && $caseEvents[1]['process'] !== $caseEvents[0]['process'], 'Deferred refresh did not run in the HTTP process.');
                    $refreshedResponse = Verification::request('http://127.0.0.1:'.$port.'/bulk-smoke/200/'.$caseName);
                    Verification::require(json_decode($refreshedResponse['body'], true, flags: JSON_THROW_ON_ERROR)['values']['one'] === 'new' && count($events($caseName)) === 2, 'HTTP refresh did not publish a reusable new value.');
                }
            }
            $caseName = $kernelMode.'-identity';
            file_put_contents($stateDirectory.'/events-'.$caseName.'.jsonl', '');
            Verification::writeJson($stateDirectory.'/source-'.$caseName.'.json', ['value' => 'wrong-context', 'values' => ['alice' => 'alice-old', 'bob' => 'bob-old', 'guest' => 'guest-old']]);
            foreach (['alice', 'bob', 'guest'] as $subject) {
                $artisan(['bulk-smoke:seed', 'http', $caseName, $subject]);
            }
            foreach (['alice', 'bob', 'guest', 'alice'] as $subject) {
                $response = Verification::request('http://127.0.0.1:'.$port.'/bulk-smoke/200/'.$caseName.'?user='.$subject);
                Verification::require(json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR)['values']['one'] === $subject.'-old', 'Sequential HTTP context was not isolated.');
            }
            usleep(1100000);
            Verification::writeJson($stateDirectory.'/source-'.$caseName.'.json', ['value' => 'wrong-context', 'values' => ['alice' => 'alice-new', 'bob' => 'bob-new', 'guest' => 'guest-new']]);
            foreach (['alice', 'bob', 'guest'] as $subject) {
                $response = Verification::request('http://127.0.0.1:'.$port.'/bulk-smoke/200/'.$caseName.'?user='.$subject);
                Verification::require(json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR)['values']['one'] === $subject.'-old', 'Deferred HTTP context returned another user value.');
            }
            foreach (['alice', 'bob', 'guest', 'alice'] as $subject) {
                $response = Verification::request('http://127.0.0.1:'.$port.'/bulk-smoke/200/'.$caseName.'?user='.$subject);
                Verification::require(json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR)['values']['one'] === $subject.'-new', 'Deferred HTTP refresh published into the wrong user context.');
            }
            Verification::require(count($events($caseName)) === 6, 'Sequential HTTP contexts reused or repeated another context refresh.');
        } finally {
            Verification::stop($server);
        }
    }

    $seed('queue', 'inline');
    usleep(1100000);
    $artisan(['bulk-smoke:inline', 'inline']);

    $caseName = 'queue-context';
    file_put_contents($stateDirectory.'/events-'.$caseName.'.jsonl', '');
    Verification::writeJson($stateDirectory.'/source-'.$caseName.'.json', ['value' => 'wrong-context', 'values' => ['alice' => 'alice-old', 'bob' => 'bob-old', 'guest' => 'guest-old']]);
    foreach (['alice', 'bob', 'guest'] as $subject) {
        $artisan(['bulk-smoke:seed', 'queue', $caseName, $subject]);
    }
    usleep(1100000);
    Verification::writeJson($stateDirectory.'/source-'.$caseName.'.json', ['value' => 'wrong-context', 'values' => ['alice' => 'alice-new', 'bob' => 'bob-new', 'guest' => 'guest-new']]);
    foreach (['alice', 'bob', 'guest'] as $subject) {
        $artisan(['bulk-smoke:queue', $caseName, '1', $subject]);
    }
    $artisan(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=3', '--timeout=10']);
    foreach (['alice', 'bob', 'guest', 'alice'] as $subject) {
        Verification::require(json_decode(trim($artisan(['bulk-smoke:read', 'queue', $caseName, $subject])), true, flags: JSON_THROW_ON_ERROR) === ['one' => $subject.'-new'], 'One queue worker leaked a user context into a later job.');
    }
    $contextEvents = $events($caseName);
    Verification::require(count($contextEvents) === 6 && count(array_unique(array_column(array_slice($contextEvents, 3), 'process'))) === 1, 'Queue context test did not process all three users in the same worker.');

    $seed('queue', 'duplicate');
    usleep(1100000);
    $artisan(['bulk-smoke:queue', 'duplicate', '2']);
    $artisan(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=3', '--backoff=0', '--timeout=10']);
    $duplicateEvents = $events('duplicate');
    Verification::require(count($duplicateEvents) === 2 && $duplicateEvents[1]['value'] === 'new' && $duplicateEvents[1]['process'] !== $duplicateEvents[0]['process'], 'Duplicate serialized jobs did not recheck the already refreshed entry.');
    Verification::require(json_decode(trim($artisan(['bulk-smoke:read', 'queue', 'duplicate'])), true, flags: JSON_THROW_ON_ERROR) === ['one' => 'new'], 'Queue refresh did not publish the new value.');

    $seed('queue', 'retry');
    usleep(1100000);
    $source('retry', 'new', 1);
    $artisan(['bulk-smoke:queue', 'retry']);
    $artisan(['queue:work', 'database', '--once', '--sleep=0', '--tries=3', '--backoff=0', '--timeout=10']);
    $jobState = json_decode(trim($artisan(['bulk-smoke:jobs'])), true, flags: JSON_THROW_ON_ERROR);
    Verification::require(count($events('retry')) === 2 && $jobState['pending'] === 1 && $jobState['failed'] === 0, 'The first failed queue attempt was not left retryable.');
    $artisan(['queue:work', 'database', '--max-jobs=1', '--max-time=10', '--sleep=1', '--tries=3', '--backoff=0', '--timeout=10']);
    $retryEvents = $events('retry');
    Verification::require(count($retryEvents) === 3 && $retryEvents[2]['value'] === 'new' && $retryEvents[1]['process'] !== $retryEvents[2]['process'], 'A new worker did not complete the failed refresh.');
    Verification::require(json_decode(trim($artisan(['bulk-smoke:read', 'queue', 'retry'])), true, flags: JSON_THROW_ON_ERROR) === ['one' => 'new'], 'Retried refresh did not publish the new value.');
    $jobState = json_decode(trim($artisan(['bulk-smoke:jobs'])), true, flags: JSON_THROW_ON_ERROR);
    Verification::require($jobState === ['pending' => 0, 'failed' => 0], 'Queue contains pending or permanently failed jobs.');

    $workerLog = $consumerDirectory.'/worker-restart.log';
    $workerReadyPath = $stateDirectory.'/worker-ready';
    if (is_file($workerReadyPath)) {
        unlink($workerReadyPath);
    }
    $worker = Verification::start([PHP_BINARY, '-n', 'artisan', 'queue:work', 'database', '--sleep=1', '--tries=3', '--timeout=10', '--max-time=30', '--no-ansi'], $consumerDirectory, $workerLog);
    try {
        $workerIdentifier = proc_get_status($worker)['pid'];
        Verification::await(fn (): bool => is_file($workerReadyPath) && (int) file_get_contents($workerReadyPath) === $workerIdentifier, 'Idle queue worker did not enter its processing loop.');
        $artisan(['queue:restart']);
        Verification::await(fn (): bool => ! proc_get_status($worker)['running'], 'Worker ignored the restart signal.', 5);
    } finally {
        Verification::stop($worker);
    }
    $seed('queue', 'restart');
    usleep(1100000);
    $artisan(['bulk-smoke:queue', 'restart']);
    $artisan(['queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=3', '--timeout=10']);
    Verification::require(count($events('restart')) === 2, 'Replacement worker did not refresh queued data.');
    $installed = Verification::json($consumerDirectory.'/vendor/composer/installed.json')['packages'];
    $framework = array_values(array_filter($installed, fn (array $package): bool => $package['name'] === 'laravel/framework'))[0];
    $report['applications'][] = ['framework' => $framework['version'], 'directory' => $consumerDirectory, 'no_dev' => true, 'redis_client_loaded' => false, 'stores' => ['array', 'file', 'database-sqlite'], 'http' => ['modern' => [200, 302, 404, 500], 'legacy_without_native_defer' => [200, 302, 404, 500]], 'queue' => ['separate_process', 'duplicate', 'retry', 'restart'], 'config_cached' => true];
    echo 'Laravel '.$framework['version']." clean-consumer smoke passed.\n";
}

Verification::require(count(array_unique($defaultPrefixes)) === 2, 'Independently installed applications received the same default namespace.');
$report['distinct_application_defaults'] = true;
Verification::writeJson($rootDirectory.'/research/execution/consumer-result.json', $report);
