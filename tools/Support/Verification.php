<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tools;

use RuntimeException;

final class Verification
{
    public static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    public static function directory(string $path): string
    {
        if (! is_dir($path) && ! mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create verification directory: '.$path);
        }

        return $path;
    }

    public static function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function writeJson(string $path, array $value): void
    {
        self::directory(dirname($path));
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public static function run(array $arguments, string $directory, string $logPath, int $timeoutSeconds = 600): string
    {
        $process = self::start($arguments, $directory, $logPath);
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                proc_close($process);
                $output = (string) file_get_contents($logPath);
                self::require($exitCode === 0, implode(' ', $arguments).' failed. See '.$logPath."\n".substr($output, -5000));

                return $output;
            }
            if (microtime(true) >= $deadline) {
                self::stop($process);
                throw new RuntimeException('Command timed out: '.implode(' ', $arguments));
            }
            usleep(20000);
        } while (true);
    }

    public static function start(array $arguments, string $directory, string $logPath)
    {
        self::directory(dirname($logPath));
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'w'], 2 => ['file', $logPath, 'a']], $pipes, $directory);
        self::require(is_resource($process), 'Could not start process.');
        fclose($pipes[0]);

        return $process;
    }

    public static function stop($process): void
    {
        if (! is_resource($process)) {
            return;
        }
        proc_terminate($process);
        $deadline = microtime(true) + 3;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
    }

    public static function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::require(is_resource($socket), 'Could not reserve a local port.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    }

    public static function await(callable $condition, string $message, float $seconds = 10): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException($message);
    }

    public static function request(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = @file_get_contents($url, false, $context);
        self::require($body !== false, 'HTTP request failed: '.$url);
        preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $matches);

        return ['status' => (int) ($matches[1] ?? 0), 'body' => $body];
    }
}
