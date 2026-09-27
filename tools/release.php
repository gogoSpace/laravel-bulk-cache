<?php

declare(strict_types=1);

use GogoSpace\BulkCache\Tools\Release\GitHubApi;
use GogoSpace\BulkCache\Tools\Release\Publisher;

require __DIR__.'/Release/GitHubApi.php';
require __DIR__.'/Release/Publisher.php';

try {
    $environment = [];
    foreach (['RELEASE_VERSION', 'RELEASE_COMMIT', 'RELEASE_VERIFICATION_RESULT', 'RELEASE_GATE_RESULT', 'GITHUB_SHA', 'GITHUB_WORKFLOW_SHA', 'GITHUB_REF', 'GITHUB_REPOSITORY', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT'] as $name) {
        $environment[$name] = getenv($name) ?: '';
    }
    $rootDirectory = dirname(__DIR__);
    $source = static function () use ($rootDirectory): array {
        $git = static function (array $arguments) use ($rootDirectory): string {
            $process = proc_open(['git', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $rootDirectory);
            if (! is_resource($process)) {
                throw new RuntimeException('The release checkout cannot be inspected.');
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0 || $output === false) {
                throw new RuntimeException('Git could not verify the release checkout.');
            }

            return trim($output);
        };

        return ['commit' => $git(['rev-parse', '--verify', 'HEAD']), 'status' => $git(['status', '--porcelain', '--untracked-files=normal']), 'readme' => (string) file_get_contents($rootDirectory.'/README.md'), 'changelog' => (string) file_get_contents($rootDirectory.'/CHANGELOG.md')];
    };
    $api = new GitHubApi(getenv('GH_TOKEN') ?: '');
    $result = (new Publisher($api->request(...), $source))->publish($environment);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
} catch (Throwable $failure) {
    fwrite(STDERR, $failure instanceof RuntimeException ? $failure->getMessage()."\n" : "Release publication failed; no response or credentials were logged.\n");
    exit(1);
}
