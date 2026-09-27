<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Concurrency;

use RuntimeException;
use Throwable;

final class WorkerProcess
{
    /** @param resource $channel */
    private function __construct(private $channel, private readonly ?int $processIdentifier) {}

    public static function start(callable $callback): self
    {
        $channels = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($channels === false) {
            throw new RuntimeException('Cannot create a worker barrier.');
        }

        $processIdentifier = pcntl_fork();

        if ($processIdentifier === -1) {
            throw new RuntimeException('Cannot fork a worker.');
        }

        if ($processIdentifier === 0) {
            fclose($channels[0]);
            $worker = new self($channels[1], null);

            try {
                $callback($worker);
                $worker->send(['event' => 'done']);
                exit(0);
            } catch (Throwable $exception) {
                $worker->send(['event' => 'failed', 'message' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()]);
                exit(1);
            }
        }

        fclose($channels[1]);

        return new self($channels[0], $processIdentifier);
    }

    /** @param array<string, mixed> $message */
    public function send(array $message): void
    {
        $encoded = json_encode($message, JSON_THROW_ON_ERROR)."\n";

        if (fwrite($this->channel, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('Could not signal a worker barrier.');
        }
    }

    /** @return array<string, mixed> */
    public function receive(string $event): array
    {
        stream_set_timeout($this->channel, 10);
        $encoded = fgets($this->channel);

        if ($encoded === false) {
            throw new RuntimeException('Worker barrier timed out or closed while waiting for '.$event.'.');
        }

        $message = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        if (($message['event'] ?? null) !== $event) {
            throw new RuntimeException('Expected worker event '.$event.', received '.$encoded);
        }

        return $message;
    }

    public function join(): void
    {
        $this->receive('done');
        pcntl_waitpid($this->processIdentifier, $status);
        fclose($this->channel);

        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Concurrent worker exited unsuccessfully.');
        }
    }

    public function kill(): void
    {
        posix_kill($this->processIdentifier, SIGKILL);
        pcntl_waitpid($this->processIdentifier, $status);
        fclose($this->channel);
    }
}
