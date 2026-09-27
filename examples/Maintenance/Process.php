<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Examples\Maintenance;

use RuntimeException;

/** Fresh-process barriers for this executable maintenance scenario only. */
final class Process
{
    /** @var resource */
    private $process;

    private string $directory;

    public function __construct(array $configuration, string $action, array $arguments)
    {
        $this->directory = $configuration['directory'].'/process-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0700);
        $arguments['barrier'] = $this->directory;
        $this->process = proc_open([
            PHP_BINARY, __DIR__.'/worker.php', $configuration['directory'].'/configuration.json', $action, json_encode($arguments, JSON_THROW_ON_ERROR),
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory.'/output.log', 'w'], 2 => ['file', $this->directory.'/error.log', 'w']], $pipes);
        if (! is_resource($this->process)) {
            throw new RuntimeException('Cannot start the held maintenance producer.');
        }
    }

    public function receive(string $event): array
    {
        return self::awaitFile($this->directory.'/'.$event);
    }

    public function release(string $event): void
    {
        self::signal($this->directory.'/'.$event, []);
    }

    public static function signal(string $path, array $payload): void
    {
        file_put_contents($path.'.next', json_encode($payload, JSON_THROW_ON_ERROR));
        rename($path.'.next', $path);
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function join(): void
    {
        $status = proc_close($this->process);
        if ($status !== 0) {
            throw new RuntimeException('Maintenance producer failed: '.file_get_contents($this->directory.'/error.log'));
        }
    }

    public static function awaitFile(string $path): array
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            if (is_file($path)) {
                return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            }
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Maintenance barrier timed out: '.basename($path));
            }
            usleep(10_000);
        } while (true);
    }
}
