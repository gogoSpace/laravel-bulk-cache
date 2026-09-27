<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tools\Release;

use RuntimeException;
use Throwable;

final class GitHubApi
{
    public function __construct(private readonly string $token)
    {
        if ($token === '' || preg_match('/[\r\n]/', $token)) {
            throw new RuntimeException('A GitHub token is required.');
        }
    }

    public function request(string $method, string $path, ?array $body): array
    {
        if (! in_array($method, ['GET', 'POST'], true) || ! str_starts_with($path, '/repos/gogoSpace/laravel-bulk-cache/')) {
            throw new RuntimeException('Unsupported release API request.');
        }
        $headers = ['Accept: application/vnd.github+json', 'Authorization: Bearer '.$this->token, 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: laravel-bulk-cache-release', 'Content-Type: application/json'];
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR), 'timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0]]);
        try {
            $response = @file_get_contents('https://api.github.com'.$path, false, $context);
            $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
            if ($response === false || ! isset($responseHeaders[0]) || preg_match('/^HTTP\/\S+ ([0-9]{3})\b/', $responseHeaders[0], $status) !== 1) {
                throw new RuntimeException;
            }
            $decoded = $response === '' ? null : json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('GitHub transport failed; its response and credentials were not logged.');
        }

        return ['status' => (int) $status[1], 'body' => $decoded];
    }
}
