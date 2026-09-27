<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tools\Release;

use Closure;
use RuntimeException;
use Throwable;

final class Publisher
{
    private Closure $transport;

    private Closure $source;

    public function __construct(callable $transport, callable $source)
    {
        $this->transport = Closure::fromCallable($transport);
        $this->source = Closure::fromCallable($source);
    }

    public function publish(array $environment): array
    {
        $version = $environment['RELEASE_VERSION'] ?? '';
        $commit = $environment['RELEASE_COMMIT'] ?? '';
        $repository = 'gogoSpace/laravel-bulk-cache';
        $runIdentifier = $environment['GITHUB_RUN_ID'] ?? '';
        $runAttempt = $environment['GITHUB_RUN_ATTEMPT'] ?? '';
        $this->require(preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-(alpha|beta|rc)\.[1-9][0-9]*\z/', $version) === 1, 'An explicit prerelease version is required.');
        $this->require(preg_match('/\A[a-f0-9]{40}\z/', $commit) === 1, 'An immutable full commit is required.');
        $this->require(($environment['RELEASE_VERIFICATION_RESULT'] ?? '') === 'success' && ($environment['RELEASE_GATE_RESULT'] ?? '') === 'success', 'Every release verification and acceptance job must succeed.');
        $this->require(($environment['GITHUB_REPOSITORY'] ?? '') === $repository && ($environment['GITHUB_REF'] ?? '') === 'refs/heads/main', 'Publication is restricted to the public repository main branch.');
        $this->require(($environment['GITHUB_SHA'] ?? '') === $commit && ($environment['GITHUB_WORKFLOW_SHA'] ?? '') === $commit, 'The workflow and tested source must identify the release commit.');
        $this->require(preg_match('/\A[1-9][0-9]*\z/', $runIdentifier) === 1 && preg_match('/\A[1-9][0-9]*\z/', $runAttempt) === 1, 'A real workflow run and attempt are required.');

        $source = ($this->source)();
        $this->require(($source['commit'] ?? '') === $commit && ($source['status'] ?? null) === '', 'The release checkout must be clean at the tested commit.');
        $this->require(preg_match('/^composer require gogospace\/laravel-bulk-cache:'.preg_quote($version, '/').'\r?$/m', $source['readme'] ?? '') === 1, 'README must pin the release version.');
        $changelog = $source['changelog'] ?? '';
        $this->require(preg_match('/^## ([^\r\n]+)\r?$/m', $changelog, $firstSection) === 1 && $firstSection[1] === $version, 'The first changelog section must identify the release version.');
        $this->require(preg_match('/^## '.preg_quote($version, '/').'\r?\n(.*?)(?=^## |\z)/ms', $changelog, $section) === 1 && trim($section[1]) !== '', 'Release notes must contain the current changelog section.');
        $notes = "Prerelease. The public API and stored cache format may change.\n\nChanges and upgrade notes: [Changelog](https://github.com/".$repository.'/blob/'.$commit."/CHANGELOG.md).\n\nVerified commit: `".$commit."`.\nVerification: https://github.com/".$repository.'/actions/runs/'.$runIdentifier;
        $tag = 'v'.$version;
        $base = '/repos/'.$repository;

        $run = $this->get($base.'/actions/runs/'.$runIdentifier);
        $this->require($run !== null && ($run['path'] ?? '') === '.github/workflows/release.yml' && ($run['event'] ?? '') === 'workflow_dispatch' && ($run['head_sha'] ?? '') === $commit && ($run['head_branch'] ?? '') === 'main' && ($run['run_attempt'] ?? null) === (int) $runAttempt, 'The live workflow run does not prove this release attempt.');
        $jobs = $this->get($base.'/actions/runs/'.$runIdentifier.'/attempts/'.$runAttempt.'/jobs?per_page=100');
        $this->require($jobs !== null && is_array($jobs['jobs'] ?? null) && is_int($jobs['total_count'] ?? null) && $jobs['total_count'] <= 100 && $jobs['total_count'] === count($jobs['jobs']), 'The workflow job list is missing or requires pagination.');
        $acceptanceJobs = array_values(array_filter($jobs['jobs'], static fn (array $job): bool => ($job['name'] ?? '') === 'Release acceptance'));
        $this->require(count($acceptanceJobs) === 1 && ($acceptanceJobs[0]['status'] ?? '') === 'completed' && ($acceptanceJobs[0]['conclusion'] ?? '') === 'success', 'The live Release acceptance job must have completed successfully.');

        $existingTag = $this->tag($base, $tag, $commit);
        $existingRelease = $this->get($base.'/releases/tags/'.$tag);
        if ($existingRelease !== null) {
            $this->require($existingTag !== null, 'An existing release has no verified annotated tag.');
            $this->verifyRelease($existingRelease, $tag, $commit, $notes, $repository);
        }
        $main = $this->get($base.'/git/ref/heads/main');
        $this->require($main !== null && ($main['object']['type'] ?? '') === 'commit' && ($main['object']['sha'] ?? '') === $commit, 'Remote main moved after verification; start a new release run.');
        if ($existingRelease !== null) {
            return ['status' => 'already_published', 'tag' => $tag, 'commit' => $commit];
        }

        if ($existingTag === null) {
            // A lost tag-object reply may leave an unreachable object. Stop safely; a later run can retry.
            $tagObject = $this->post($base.'/git/tags', ['tag' => $tag, 'message' => 'Release '.$tag, 'object' => $commit, 'type' => 'commit']);
            $this->require(preg_match('/\A[a-f0-9]{40}\z/', $tagObject['sha'] ?? '') === 1 && ($tagObject['tag'] ?? '') === $tag && ($tagObject['object']['type'] ?? '') === 'commit' && ($tagObject['object']['sha'] ?? '') === $commit, 'GitHub did not return the requested annotated tag object.');
            try {
                $this->post($base.'/git/refs', ['ref' => 'refs/tags/'.$tag, 'sha' => $tagObject['sha']]);
            } catch (RuntimeException $failure) {
                if ($this->tag($base, $tag, $commit) === null) {
                    throw $failure;
                }
            }
            $this->require($this->tag($base, $tag, $commit) !== null, 'The newly created tag could not be verified.');
        }
        $payload = ['tag_name' => $tag, 'target_commitish' => $commit, 'name' => $tag, 'body' => $notes, 'draft' => false, 'prerelease' => true, 'make_latest' => 'false'];
        try {
            $this->post($base.'/releases', $payload);
        } catch (RuntimeException $failure) {
            $recoveredRelease = $this->get($base.'/releases/tags/'.$tag);
            if ($recoveredRelease === null) {
                throw $failure;
            }
            $this->verifyRelease($recoveredRelease, $tag, $commit, $notes, $repository);
        }
        $this->require($this->tag($base, $tag, $commit) !== null, 'The published tag could not be verified.');
        $release = $this->get($base.'/releases/tags/'.$tag);
        $this->require($release !== null, 'The published release could not be verified.');
        $this->verifyRelease($release, $tag, $commit, $notes, $repository);

        return ['status' => 'published', 'tag' => $tag, 'commit' => $commit];
    }

    private function tag(string $base, string $tag, string $commit): ?array
    {
        $reference = $this->get($base.'/git/ref/tags/'.$tag);
        if ($reference === null) {
            return null;
        }
        $this->require(($reference['ref'] ?? '') === 'refs/tags/'.$tag && ($reference['object']['type'] ?? '') === 'tag' && preg_match('/\A[a-f0-9]{40}\z/', $reference['object']['sha'] ?? '') === 1, 'The existing release tag must be annotated.');
        $object = $this->get($base.'/git/tags/'.$reference['object']['sha']);
        $this->require($object !== null && ($object['tag'] ?? '') === $tag && ($object['object']['type'] ?? '') === 'commit' && ($object['object']['sha'] ?? '') === $commit, 'The existing annotated tag identifies a different commit.');

        return $reference;
    }

    private function verifyRelease(array $release, string $tag, string $commit, string $notes, string $repository): void
    {
        // A later invocation has a different run URL, but must retain exactly the same release notes.
        $runLink = '~\nVerification: https://github\.com/'.preg_quote($repository, '~').'/actions/runs/[1-9][0-9]*\z~';
        $existingNotes = $release['body'] ?? '';
        $this->require(($release['tag_name'] ?? '') === $tag && ($release['name'] ?? '') === $tag && ($release['target_commitish'] ?? '') === $commit && ($release['draft'] ?? null) === false && ($release['prerelease'] ?? null) === true && is_string($existingNotes) && preg_match($runLink, $existingNotes) === 1 && preg_replace($runLink, '', $existingNotes) === preg_replace($runLink, '', $notes), 'An existing release conflicts with the verified prerelease.');
    }

    private function get(string $path): ?array
    {
        $response = $this->request('GET', $path, null);
        if ($response['status'] === 404) {
            return null;
        }
        $this->require($response['status'] === 200 && is_array($response['body']), 'GitHub could not confirm the required release state.');

        return $response['body'];
    }

    private function post(string $path, array $body): array
    {
        $response = $this->request('POST', $path, $body);
        $this->require($response['status'] === 201 && is_array($response['body']), 'GitHub did not confirm the release mutation; rerun after checking its state.');

        return $response['body'];
    }

    private function request(string $method, string $path, ?array $body): array
    {
        try {
            $response = ($this->transport)($method, $path, $body);
        } catch (Throwable) {
            throw new RuntimeException('GitHub request failed; its response and credentials were not logged.');
        }
        $this->require(is_array($response) && is_int($response['status'] ?? null) && array_key_exists('body', $response), 'GitHub returned an invalid response.');

        return $response;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
