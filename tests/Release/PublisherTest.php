<?php

declare(strict_types=1);

namespace GogoSpace\BulkCache\Tests\Release;

use Closure;
use GogoSpace\BulkCache\Tools\Release\Publisher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../tools/Release/Publisher.php';

final class PublisherTest extends TestCase
{
    private array $environment;

    private array $source;

    private ReleaseApiFixture $github;

    protected function setUp(): void
    {
        $commit = str_repeat('a', 40);
        $this->environment = ['RELEASE_VERSION' => '0.1.0-beta.3', 'RELEASE_COMMIT' => $commit, 'RELEASE_VERIFICATION_RESULT' => 'success', 'RELEASE_GATE_RESULT' => 'success', 'GITHUB_SHA' => $commit, 'GITHUB_WORKFLOW_SHA' => $commit, 'GITHUB_REF' => 'refs/heads/main', 'GITHUB_REPOSITORY' => 'gogoSpace/laravel-bulk-cache', 'GITHUB_RUN_ID' => '42', 'GITHUB_RUN_ATTEMPT' => '1'];
        $this->source = ['commit' => $commit, 'status' => '', 'readme' => "```shell\ncomposer require gogospace/laravel-bulk-cache:0.1.0-beta.3\n```\n", 'changelog' => "# Changelog\n\n## 0.1.0-beta.3\n\n- Preserve rejected publications. Read [upgrade procedure](docs/upgrade.md).\n\n## 0.1.0-beta.2\n\n- Older release.\n"];
        $this->github = new ReleaseApiFixture($commit);
    }

    public function test_it_publishes_only_the_exact_verified_annotated_tag_then_prerelease(): void
    {
        self::assertSame(['status' => 'published', 'tag' => 'v0.1.0-beta.3', 'commit' => $this->environment['RELEASE_COMMIT']], $this->publish());
        $mutations = $this->github->mutations();
        self::assertSame(['/git/tags', '/git/refs', '/releases'], array_column($mutations, 'path'));
        self::assertSame(['tag' => 'v0.1.0-beta.3', 'message' => 'Release v0.1.0-beta.3', 'object' => $this->environment['RELEASE_COMMIT'], 'type' => 'commit'], $mutations[0]['body']);
        self::assertSame('refs/tags/v0.1.0-beta.3', $mutations[1]['body']['ref']);
        self::assertSame($this->environment['RELEASE_COMMIT'], $mutations[2]['body']['target_commitish']);
        self::assertFalse($mutations[2]['body']['draft']);
        self::assertTrue($mutations[2]['body']['prerelease']);
        self::assertSame('false', $mutations[2]['body']['make_latest']);
        self::assertStringContainsString('[Changelog](https://github.com/gogoSpace/laravel-bulk-cache/blob/'.$this->environment['RELEASE_COMMIT'].'/CHANGELOG.md)', $mutations[2]['body']['body']);
        self::assertDoesNotMatchRegularExpression('~\]\((?!https://)[^)]+\)~', $mutations[2]['body']['body']);
        self::assertStringNotContainsString('Older release', $mutations[2]['body']['body']);
        self::assertStringContainsString('https://github.com/gogoSpace/laravel-bulk-cache/actions/runs/42', $mutations[2]['body']['body']);
        $firstMutation = array_search($mutations[0], $this->github->requests, true);
        self::assertGreaterThan(3, $firstMutation);
        self::assertSame('/git/ref/heads/main', $this->github->requests[$firstMutation - 1]['path']);
    }

    #[DataProvider('invalidEnvironment')]
    public function test_invalid_inputs_and_gate_results_cannot_make_any_api_request(string $name, string $value): void
    {
        $this->environment[$name] = $value;
        $this->rejectWithoutMutation();
        self::assertSame([], $this->github->requests);
    }

    public static function invalidEnvironment(): iterable
    {
        foreach (['1.2.3', 'v1.2.3-beta.1', '01.2.3-beta.1', '1.2.3-beta.0', '1.2.3-beta.01', "1.2.3-beta.1\n", '1.2.3-beta.1; touch /tmp/release', '$(touch /tmp/release)', '1.2.3-beta.1+metadata'] as $version) {
            yield 'version '.$version => ['RELEASE_VERSION', $version];
        }
        foreach (['failure', 'cancelled', 'skipped', 'neutral', ''] as $result) {
            yield 'verification '.$result => ['RELEASE_VERIFICATION_RESULT', $result];
            yield 'gate '.$result => ['RELEASE_GATE_RESULT', $result];
        }
        yield 'short commit' => ['RELEASE_COMMIT', 'aaaaaaa'];
        yield 'uppercase commit' => ['RELEASE_COMMIT', str_repeat('A', 40)];
        yield 'mutable ref' => ['RELEASE_COMMIT', 'main'];
        yield 'other source' => ['GITHUB_SHA', str_repeat('b', 40)];
        yield 'other workflow' => ['GITHUB_WORKFLOW_SHA', str_repeat('b', 40)];
        yield 'other branch' => ['GITHUB_REF', 'refs/heads/release'];
        yield 'tag event' => ['GITHUB_REF', 'refs/tags/v0.1.0-beta.3'];
        yield 'fork' => ['GITHUB_REPOSITORY', 'someone/laravel-bulk-cache'];
        yield 'invalid run' => ['GITHUB_RUN_ID', '42/other'];
        yield 'invalid attempt' => ['GITHUB_RUN_ATTEMPT', '0'];
    }

    #[DataProvider('invalidSource')]
    public function test_unverified_checkout_or_release_metadata_cannot_publish(string $name, string $value): void
    {
        $this->source[$name] = $value;
        $this->rejectWithoutMutation();
        self::assertSame([], $this->github->requests);
    }

    public static function invalidSource(): iterable
    {
        yield 'different commit' => ['commit', str_repeat('b', 40)];
        yield 'dirty source' => ['status', ' M README.md'];
        yield 'untracked source' => ['status', '?? source.php'];
        yield 'old readme' => ['readme', 'composer require gogospace/laravel-bulk-cache:0.1.0-beta.2'];
        yield 'missing readme' => ['readme', ''];
        yield 'old current changelog' => ['changelog', "## 0.1.0-beta.2\n\nOld\n\n## 0.1.0-beta.3\n\nFuture\n"];
        yield 'empty notes' => ['changelog', "## 0.1.0-beta.3\n\n## 0.1.0-beta.2\nOld\n"];
    }

    #[DataProvider('invalidLiveRun')]
    public function test_environment_success_cannot_substitute_for_live_workflow_provenance(string $name, mixed $value): void
    {
        $this->github->resources['/actions/runs/42'][$name] = $value;
        $this->rejectWithoutMutation();
    }

    public static function invalidLiveRun(): iterable
    {
        yield 'wrong workflow' => ['path', '.github/workflows/tests.yml'];
        yield 'push event' => ['event', 'push'];
        yield 'different tested commit' => ['head_sha', str_repeat('b', 40)];
        yield 'unmerged branch' => ['head_branch', 'candidate'];
        yield 'earlier attempt' => ['run_attempt', 2];
    }

    #[DataProvider('invalidLiveAcceptance')]
    public function test_live_acceptance_must_be_one_completed_successful_job(array $jobs, int $totalCount): void
    {
        $this->github->resources['/actions/runs/42/attempts/1/jobs?per_page=100'] = ['total_count' => $totalCount, 'jobs' => $jobs];
        $this->rejectWithoutMutation();
    }

    public static function invalidLiveAcceptance(): iterable
    {
        $successful = ['name' => 'Release acceptance', 'status' => 'completed', 'conclusion' => 'success'];
        yield 'missing' => [[], 0];
        yield 'duplicate' => [[$successful, $successful], 2];
        yield 'wrong context' => [[array_replace($successful, ['name' => 'acceptance'])], 1];
        yield 'running' => [[array_replace($successful, ['status' => 'in_progress'])], 1];
        foreach (['failure', 'cancelled', 'skipped', 'neutral', null] as $conclusion) {
            yield 'conclusion '.($conclusion ?? 'null') => [[array_replace($successful, ['conclusion' => $conclusion])], 1];
        }
        yield 'more pages' => [[$successful], 101];
        yield 'truncated response' => [[$successful], 2];
    }

    public function test_main_advancing_after_all_other_checks_prevents_every_mutation(): void
    {
        $this->github->resources['/git/ref/heads/main']['object']['sha'] = str_repeat('c', 40);
        $this->rejectWithoutMutation();
        self::assertSame('/git/ref/heads/main', end($this->github->requests)['path']);
    }

    public function test_existing_lightweight_tag_cannot_be_replaced_even_at_the_right_commit(): void
    {
        $this->github->resources['/git/ref/tags/v0.1.0-beta.3'] = ['ref' => 'refs/tags/v0.1.0-beta.3', 'object' => ['type' => 'commit', 'sha' => $this->environment['RELEASE_COMMIT']]];
        $this->rejectWithoutMutation();
    }

    public function test_existing_annotated_tag_at_another_commit_cannot_be_replaced(): void
    {
        $this->github->seedTag(str_repeat('c', 40));
        $this->rejectWithoutMutation();
    }

    public function test_correct_tag_without_release_recovers_by_creating_only_the_release(): void
    {
        $this->github->seedTag($this->environment['RELEASE_COMMIT']);
        self::assertSame('published', $this->publish()['status']);
        self::assertSame(['/releases'], array_column($this->github->mutations(), 'path'));
    }

    public function test_repeated_success_is_read_only_even_from_a_later_verified_run(): void
    {
        $this->publish();
        $originalRelease = $this->github->resources['/releases/tags/v0.1.0-beta.3'];
        $this->github->requests = [];
        $this->environment['GITHUB_RUN_ID'] = '43';
        $this->github->resources['/actions/runs/43'] = $this->github->resources['/actions/runs/42'];
        $this->github->resources['/actions/runs/43/attempts/1/jobs?per_page=100'] = $this->github->resources['/actions/runs/42/attempts/1/jobs?per_page=100'];
        self::assertSame('already_published', $this->publish()['status']);
        self::assertSame([], $this->github->mutations());
        self::assertSame($originalRelease, $this->github->resources['/releases/tags/v0.1.0-beta.3']);
    }

    #[DataProvider('conflictingRelease')]
    public function test_existing_release_conflicts_are_rejected_without_modification(string $name, mixed $value): void
    {
        $this->publish();
        $this->github->resources['/releases/tags/v0.1.0-beta.3'][$name] = $value;
        $this->github->requests = [];
        $this->rejectWithoutMutation();
    }

    public static function conflictingRelease(): iterable
    {
        yield 'different tag' => ['tag_name', 'v0.1.0-beta.2'];
        yield 'different title' => ['name', 'Changed release'];
        yield 'different target' => ['target_commitish', str_repeat('c', 40)];
        yield 'draft' => ['draft', true];
        yield 'stable release' => ['prerelease', false];
        yield 'different notes' => ['body', 'Unreviewed claims'];
    }

    #[DataProvider('lostMutationReplies')]
    public function test_a_lost_reply_is_confirmed_from_live_state_without_repeating_the_write(string $path): void
    {
        $this->github->after = static function (string $method, string $requestPath) use ($path): void {
            if ($method === 'POST' && $requestPath === $path) {
                throw new RuntimeException('token-secret and private HTTP response');
            }
        };
        self::assertSame('published', $this->publish()['status']);
        self::assertCount(3, $this->github->mutations());
        self::assertCount(1, array_filter($this->github->mutations(), static fn (array $request): bool => $request['path'] === $path));
    }

    public static function lostMutationReplies(): iterable
    {
        yield 'tag reference' => ['/git/refs'];
        yield 'release' => ['/releases'];
    }

    public function test_a_lost_tag_object_reply_stops_without_creating_a_public_tag(): void
    {
        $this->github->after = static function (string $method, string $path): void {
            if ($method === 'POST' && $path === '/git/tags') {
                throw new RuntimeException('token-secret');
            }
        };
        try {
            $this->publish();
            self::fail('Expected an ambiguous tag-object write to stop.');
        } catch (RuntimeException $failure) {
            self::assertStringNotContainsString('token-secret', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
        self::assertSame(['/git/tags'], array_column($this->github->mutations(), 'path'));
        self::assertArrayNotHasKey('/git/ref/tags/v0.1.0-beta.3', $this->github->resources);
    }

    public function test_failed_release_creation_stops_then_a_fresh_invocation_recovers_the_existing_tag(): void
    {
        $this->github->before = static function (string $method, string $path): void {
            if ($method === 'POST' && $path === '/releases') {
                throw new RuntimeException('token-secret');
            }
        };
        try {
            $this->publish();
            self::fail('Expected failed publication.');
        } catch (RuntimeException $failure) {
            self::assertStringNotContainsString('token-secret', $failure->getMessage());
        }
        self::assertArrayHasKey('/git/ref/tags/v0.1.0-beta.3', $this->github->resources);
        self::assertArrayNotHasKey('/releases/tags/v0.1.0-beta.3', $this->github->resources);
        $this->github->before = null;
        $this->github->requests = [];
        self::assertSame('published', $this->publish()['status']);
        self::assertSame(['/releases'], array_column($this->github->mutations(), 'path'));
    }

    public function test_read_errors_never_become_absence_or_expose_transport_details(): void
    {
        $this->github->before = static function (): void {
            throw new RuntimeException('Bearer token-secret; private response');
        };
        $failure = $this->rejectWithoutMutation();
        self::assertStringNotContainsString('token-secret', $failure->getMessage());
        self::assertNull($failure->getPrevious());
    }

    #[DataProvider('unconfirmedReadStatuses')]
    public function test_http_errors_and_redirects_cannot_be_treated_as_a_missing_tag(int $status): void
    {
        $this->github->responses['GET /git/ref/tags/v0.1.0-beta.3'] = ['status' => $status, 'body' => ['message' => 'token-secret response']];
        $failure = $this->rejectWithoutMutation();
        self::assertStringNotContainsString('token-secret', $failure->getMessage());
    }

    public static function unconfirmedReadStatuses(): iterable
    {
        foreach ([204, 301, 302, 401, 403, 429, 500, 502] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function test_a_conflicting_tag_created_concurrently_is_never_overwritten_or_released(): void
    {
        $this->github->before = function (string $method, string $path): void {
            if ($method === 'POST' && $path === '/git/refs') {
                $this->github->seedTag(str_repeat('c', 40));
            }
        };
        try {
            $this->publish();
            self::fail('Expected the concurrent tag conflict to stop publication.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('different commit', $failure->getMessage());
        }
        self::assertSame(['/git/tags', '/git/refs'], array_column($this->github->mutations(), 'path'));
        self::assertSame(str_repeat('c', 40), $this->github->resources['/git/tags/'.str_repeat('b', 40)]['object']['sha']);
        self::assertArrayNotHasKey('/releases/tags/v0.1.0-beta.3', $this->github->resources);
    }

    public function test_an_unexpected_tag_object_response_is_not_made_public(): void
    {
        $this->github->responses['POST /git/tags'] = ['status' => 201, 'body' => ['sha' => str_repeat('b', 40), 'tag' => 'v0.1.0-beta.3', 'object' => ['type' => 'commit', 'sha' => str_repeat('c', 40)]]];
        try {
            $this->publish();
            self::fail('Expected the wrong tag object to be rejected.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('annotated tag object', $failure->getMessage());
        }
        self::assertSame(['/git/tags'], array_column($this->github->mutations(), 'path'));
        self::assertArrayNotHasKey('/git/ref/tags/v0.1.0-beta.3', $this->github->resources);
    }

    public function test_a_failed_mutation_is_not_retried_when_fresh_reads_confirm_absence(): void
    {
        $this->github->seedTag($this->environment['RELEASE_COMMIT']);
        $this->github->responses['POST /releases'] = ['status' => 500, 'body' => ['message' => 'token-secret response']];
        try {
            $this->publish();
            self::fail('Expected the failed release write to stop.');
        } catch (RuntimeException $failure) {
            self::assertStringNotContainsString('token-secret', $failure->getMessage());
        }
        self::assertSame(['/releases'], array_column($this->github->mutations(), 'path'));
        self::assertSame('/releases/tags/v0.1.0-beta.3', end($this->github->requests)['path']);
        self::assertArrayNotHasKey('/releases/tags/v0.1.0-beta.3', $this->github->resources);
    }

    private function publish(): array
    {
        return (new Publisher($this->github->request(...), fn (): array => $this->source))->publish($this->environment);
    }

    private function rejectWithoutMutation(): RuntimeException
    {
        try {
            $this->publish();
        } catch (RuntimeException $failure) {
            self::assertSame([], $this->github->mutations());

            return $failure;
        }
        self::fail('An invalid release was accepted.');
    }
}

final class ReleaseApiFixture
{
    public array $resources;

    public array $requests = [];

    public array $responses = [];

    public ?Closure $before = null;

    public ?Closure $after = null;

    public function __construct(string $commit)
    {
        $this->resources = ['/actions/runs/42' => ['path' => '.github/workflows/release.yml', 'event' => 'workflow_dispatch', 'head_sha' => $commit, 'head_branch' => 'main', 'run_attempt' => 1, 'status' => 'in_progress', 'conclusion' => null], '/actions/runs/42/attempts/1/jobs?per_page=100' => ['total_count' => 2, 'jobs' => [['name' => 'Release acceptance', 'status' => 'completed', 'conclusion' => 'success'], ['name' => 'Publish', 'status' => 'in_progress', 'conclusion' => null]]], '/git/ref/heads/main' => ['ref' => 'refs/heads/main', 'object' => ['type' => 'commit', 'sha' => $commit]]];
    }

    public function seedTag(string $commit): void
    {
        $objectIdentifier = str_repeat('b', 40);
        $this->resources['/git/tags/'.$objectIdentifier] = ['tag' => 'v0.1.0-beta.3', 'sha' => $objectIdentifier, 'object' => ['type' => 'commit', 'sha' => $commit]];
        $this->resources['/git/ref/tags/v0.1.0-beta.3'] = ['ref' => 'refs/tags/v0.1.0-beta.3', 'object' => ['type' => 'tag', 'sha' => $objectIdentifier]];
    }

    public function mutations(): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => $request['method'] !== 'GET'));
    }

    public function request(string $method, string $path, ?array $body): array
    {
        $prefix = '/repos/gogoSpace/laravel-bulk-cache';
        TestCase::assertStringStartsWith($prefix.'/', $path);
        $path = substr($path, strlen($prefix));
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $body];
        if ($this->before !== null) {
            ($this->before)($method, $path);
        }
        if (isset($this->responses[$method.' '.$path])) {
            return $this->responses[$method.' '.$path];
        }
        if ($method === 'GET') {
            return isset($this->resources[$path]) ? ['status' => 200, 'body' => $this->resources[$path]] : ['status' => 404, 'body' => ['message' => 'Not Found']];
        }
        TestCase::assertSame('POST', $method, 'No update, deletion or force operation is permitted.');
        if ($path === '/git/tags') {
            $identifier = sha1(json_encode($body, JSON_THROW_ON_ERROR));
            $result = ['tag' => $body['tag'], 'sha' => $identifier, 'object' => ['type' => $body['type'], 'sha' => $body['object']]];
            $this->resources['/git/tags/'.$identifier] = $result;
        } elseif ($path === '/git/refs') {
            if (isset($this->resources['/git/ref/'.substr($body['ref'], strlen('refs/'))])) {
                return ['status' => 422, 'body' => ['message' => 'Reference already exists']];
            }
            $result = ['ref' => $body['ref'], 'object' => ['type' => 'tag', 'sha' => $body['sha']]];
            $this->resources['/git/ref/'.substr($body['ref'], strlen('refs/'))] = $result;
        } elseif ($path === '/releases') {
            if (isset($this->resources['/releases/tags/'.$body['tag_name']])) {
                return ['status' => 422, 'body' => ['message' => 'Release already exists']];
            }
            $result = $body + ['id' => 100];
            $this->resources['/releases/tags/'.$body['tag_name']] = $result;
        } else {
            TestCase::fail('Unexpected API mutation.');
        }
        if ($this->after !== null) {
            ($this->after)($method, $path);
        }

        return ['status' => 201, 'body' => $result];
    }
}
