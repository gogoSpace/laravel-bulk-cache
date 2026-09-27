<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Support;

use RuntimeException;

/** A disposable process owned exclusively by the calling test suite. */
final class RedisServer
{
    /** @var resource|null */
    private $process = null;

    public readonly string $directory;

    public readonly int $port;

    private readonly int $ownerProcess;

    public function __construct(?int $primaryPort = null)
    {
        $this->ownerProcess = getmypid();
        $this->directory = dirname(__DIR__, 2).'/.runtime/redis/'.bin2hex(random_bytes(8));

        if (! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the isolated Redis directory.');
        }

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        if ($listener === false) {
            throw new RuntimeException('Cannot reserve a local Redis port: '.$errorMessage);
        }

        $address = stream_socket_get_name($listener, false);
        $this->port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($listener);

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory.'/server.log', 'a'],
            2 => ['file', $this->directory.'/server.log', 'a'],
        ];
        $command = [
            'redis-server', '--bind', '127.0.0.1', '--port', (string) $this->port,
            '--save', '', '--appendonly', 'no', '--protected-mode', 'yes',
            '--dir', $this->directory, '--maxmemory-policy', 'noeviction',
        ];
        if ($primaryPort !== null) {
            array_push($command, '--replicaof', '127.0.0.1', (string) $primaryPort);
        }
        $this->process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($this->process)) {
            throw new RuntimeException('Cannot start the isolated Redis process.');
        }

        $deadline = hrtime(true) + 5_000_000_000;

        do {
            $socket = @stream_socket_client('tcp://127.0.0.1:'.$this->port, $errorNumber, $errorMessage, 0.1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            if (! proc_get_status($this->process)['running']) {
                throw new RuntimeException('Isolated Redis exited: '.file_get_contents($this->directory.'/server.log'));
            }

            usleep(10_000);
        } while (hrtime(true) < $deadline);

        $this->stop();

        throw new RuntimeException('The isolated Redis process did not become ready.');
    }

    public function stop(): void
    {
        if ($this->ownerProcess !== getmypid() || ! is_resource($this->process)) {
            return;
        }

        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
