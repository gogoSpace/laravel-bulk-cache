<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Examples;

use GogoSpace\BulkCache\BulkCacheServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Redis\RedisServiceProvider;
use Predis\Client;
use RuntimeException;

/** Disposable scenario infrastructure; the application pattern lives in FavoriteApplication. */
final class ExampleEnvironment
{
    public readonly Application $application;

    public readonly array $configuration;

    /** @var resource|null */
    private $server = null;

    public function __construct(?string $client = null)
    {
        $client ??= class_exists(\Redis::class) ? 'phpredis' : 'predis';
        if (($client === 'phpredis' && ! class_exists(\Redis::class)) || ($client === 'predis' && ! class_exists(Client::class))) {
            throw new RuntimeException('This executable scenario needs PhpRedis or Predis; install the chosen optional integration.');
        }
        $directory = dirname(__DIR__, 2).'/.runtime/examples/'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create the disposable example directory.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if ($listener === false) {
            throw new RuntimeException('Cannot reserve an example Redis port: '.$errorMessage);
        }
        $address = stream_socket_get_name($listener, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($listener);
        $this->server = proc_open([
            'redis-server', '--bind', '127.0.0.1', '--port', (string) $port,
            '--save', '', '--appendonly', 'no', '--protected-mode', 'yes', '--dir', $directory,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/redis.log', 'a'], 2 => ['file', $directory.'/redis.log', 'a']], $pipes);
        if (! is_resource($this->server)) {
            throw new RuntimeException('Cannot start the disposable Redis server.');
        }
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorNumber, $errorMessage, 0.1);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            if (! proc_get_status($this->server)['running'] || hrtime(true) >= $deadline) {
                $this->close();
                throw new RuntimeException('Disposable Redis did not start: '.file_get_contents($directory.'/redis.log'));
            }
            usleep(10_000);
        } while (true);
        $autoload = array_values(array_filter(get_included_files(), static fn (string $path): bool => str_ends_with($path, '/vendor/autoload.php')))[0] ?? null;
        if ($autoload === null) {
            throw new RuntimeException('Require the application vendor/autoload.php before running the example.');
        }
        touch($directory.'/source.sqlite');
        $this->configuration = compact('directory', 'client', 'port', 'autoload');
        file_put_contents($directory.'/configuration.json', json_encode($this->configuration, JSON_THROW_ON_ERROR));
        $this->application = self::boot($this->configuration);
    }

    public static function boot(array $configuration): Application
    {
        $application = new Application($configuration['directory']);
        $application->instance('config', new Repository([
            'app' => ['name' => 'Bulk cache examples', 'env' => 'testing', 'key' => 'synthetic-example-only'],
            'cache' => ['default' => 'file', 'stores' => ['file' => ['driver' => 'file', 'path' => $configuration['directory'].'/cache']]],
            'database' => [
                'default' => 'example',
                'connections' => ['example' => ['driver' => 'sqlite', 'database' => $configuration['directory'].'/source.sqlite', 'prefix' => '']],
                'redis' => ['client' => $configuration['client'], 'default' => ['host' => '127.0.0.1', 'port' => $configuration['port'], 'database' => 0, 'timeout' => 1, 'read_timeout' => 1]],
            ],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'bulk-cache' => ['driver' => 'redis', 'prefix' => 'example-'.basename($configuration['directory'])],
        ]));
        foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, CacheServiceProvider::class, DatabaseServiceProvider::class, RedisServiceProvider::class, QueueServiceProvider::class, BulkCacheServiceProvider::class] as $provider) {
            $application->register($provider);
        }
        $application->boot();

        return $application;
    }

    public function worker(string $action, array $arguments = []): array
    {
        $process = proc_open([
            PHP_BINARY, __DIR__.'/worker.php', $this->configuration['directory'].'/configuration.json', $action,
            json_encode($arguments, JSON_THROW_ON_ERROR),
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start a fresh example process.');
        }
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException('Example worker failed: '.$output.$errors);
        }

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    public function allowCacheCommands(bool $allowed): void
    {
        // Only this scenario's disposable Redis is affected; cached old values survive the fault.
        $this->application['redis']->connection()->command('acl', ['SETUSER', 'default', $allowed ? '+eval' : '-eval']);
    }

    public static function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    public function close(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
