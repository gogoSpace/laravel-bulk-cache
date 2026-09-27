<?php

namespace GogoSpace\BulkCache\Tests\Release;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class WorkflowTest extends TestCase
{
    public function test_publication_requires_an_explicit_manual_request(): void
    {
        $workflow = $this->workflow('release.yml');
        self::assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $inputs = $workflow['on']['workflow_dispatch']['inputs'];
        self::assertSame('boolean', $inputs['publish']['type']);
        self::assertFalse($inputs['publish']['default']);
        foreach (['version', 'commit'] as $input) {
            self::assertSame('string', $inputs[$input]['type']);
            self::assertTrue($inputs[$input]['required']);
        }
        self::assertFalse($workflow['concurrency']['cancel-in-progress']);
        self::assertNotEmpty($workflow['concurrency']['group']);
    }

    public function test_only_the_publisher_can_write_to_the_repository(): void
    {
        foreach (['release.yml', 'tests.yml'] as $filename) {
            $workflow = $this->workflow($filename);
            self::assertSame(['contents' => 'read'], $workflow['permissions']);
            foreach ($workflow['jobs'] as $identifier => $job) {
                $permissions = $job['permissions'] ?? $workflow['permissions'];
                $expected = $filename === 'release.yml' && $identifier === 'publish'
                    ? ['contents' => 'write', 'actions' => 'read']
                    : ['contents' => 'read'];
                self::assertSame($expected, $permissions, $filename.':'.$identifier);
                self::assertArrayNotHasKey('secrets', $job, 'Reusable verification must not inherit publication secrets.');
            }
        }
    }

    public function test_publication_depends_on_every_verification_gate(): void
    {
        $release = $this->workflow('release.yml');
        $jobs = $release['jobs'];
        self::assertEqualsCanonicalizing(['prepare', 'verify', 'release-acceptance', 'publish'], array_keys($jobs));
        self::assertSame(['prepare'], (array) $jobs['verify']['needs']);
        self::assertSame('./.github/workflows/tests.yml', $jobs['verify']['uses']);
        self::assertSame('${{ needs.prepare.outputs.commit }}', $jobs['verify']['with']['commit']);
        self::assertEqualsCanonicalizing(['prepare', 'verify'], $jobs['release-acceptance']['needs']);
        self::assertSame('Release acceptance', $jobs['release-acceptance']['name']);
        self::assertSame('always()', $this->expression($jobs['release-acceptance']['if']));
        self::assertEqualsCanonicalizing(['prepare', 'verify', 'release-acceptance'], $jobs['publish']['needs']);

        $conditions = array_map('trim', explode('&&', $this->expression($jobs['publish']['if'])));
        self::assertEqualsCanonicalizing([
            'inputs.publish',
            "needs.prepare.result == 'success'",
            "needs.verify.result == 'success'",
            "needs.release-acceptance.result == 'success'",
        ], $conditions, 'Every prerequisite must be successful and publication explicitly requested.');

        $verification = $this->workflow('tests.yml');
        self::assertEqualsCanonicalizing(['package', 'distribution', 'acceptance'], array_keys($verification['jobs']));
        self::assertEqualsCanonicalizing(['package', 'distribution'], $verification['jobs']['acceptance']['needs']);
        self::assertSame('always()', $this->expression($verification['jobs']['acceptance']['if']));
        foreach (['package', 'distribution'] as $identifier) {
            self::assertArrayNotHasKey('if', $verification['jobs'][$identifier], 'A required matrix must not silently skip.');
        }
    }

    public function test_reusable_verification_checks_out_the_validated_commit(): void
    {
        $workflow = $this->workflow('tests.yml');
        $input = $workflow['on']['workflow_call']['inputs']['commit'];
        self::assertSame('string', $input['type']);
        self::assertTrue($input['required']);
        self::assertEqualsCanonicalizing(['push', 'pull_request', 'workflow_dispatch', 'workflow_call'], array_keys($workflow['on']));

        $checkouts = 0;
        foreach ($workflow['jobs'] as $job) {
            foreach ($job['steps'] ?? [] as $step) {
                if (str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
                    self::assertSame('inputs.commit || github.sha', $this->expression($step['with']['ref']));
                    self::assertFalse($step['with']['persist-credentials']);
                    $checkouts++;
                }
            }
        }
        self::assertSame(2, $checkouts);

        $release = $this->workflow('release.yml');
        $prepareCheckouts = array_values(array_filter($release['jobs']['prepare']['steps'], static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/checkout@')));
        self::assertCount(1, $prepareCheckouts);
        self::assertSame('${{ github.sha }}', $prepareCheckouts[0]['with']['ref']);
        self::assertFalse($prepareCheckouts[0]['with']['persist-credentials']);

        $publisher = $release['jobs']['publish'];
        $checkouts = array_values(array_filter($publisher['steps'], static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/checkout@')));
        self::assertCount(1, $checkouts);
        self::assertSame('${{ needs.prepare.outputs.commit }}', $checkouts[0]['with']['ref']);
        self::assertFalse($checkouts[0]['with']['persist-credentials']);
    }

    public function test_each_matrix_verifies_its_checkout_before_running_package_commands(): void
    {
        $workflow = $this->workflow('tests.yml');
        [$exitCode, $output] = $this->executeShell('git rev-parse HEAD', []);
        self::assertSame(0, $exitCode);
        foreach (['package', 'distribution'] as $identifier) {
            $steps = $workflow['jobs'][$identifier]['steps'];
            $checks = array_filter($steps, static fn (array $step): bool => ($step['env']['EXPECTED_COMMIT'] ?? null) === '${{ inputs.commit || github.sha }}');
            self::assertCount(1, $checks);
            $position = array_key_first($checks);
            self::assertSame(1, $position, 'Commit verification must immediately follow checkout.');
            [$validExit] = $this->executeShell($checks[$position]['run'], ['EXPECTED_COMMIT' => trim($output)]);
            self::assertSame(0, $validExit);
            [$invalidExit] = $this->executeShell($checks[$position]['run'], ['EXPECTED_COMMIT' => str_repeat('a', 40)]);
            self::assertNotSame(0, $invalidExit, 'The matrix must fail when checkout resolves a different commit.');
        }
    }

    public function test_verification_keeps_the_supported_matrices_and_operational_commands(): void
    {
        $jobs = $this->workflow('tests.yml')['jobs'];
        self::assertEqualsCanonicalizing([
            ['php' => '8.3', 'testbench' => '^10.0'],
            ['php' => '8.4', 'testbench' => '^10.0'],
            ['php' => '8.4', 'testbench' => '^11.0'],
            ['php' => '8.5', 'testbench' => '^11.0'],
        ], $jobs['package']['strategy']['matrix']['include']);
        self::assertEqualsCanonicalizing(['8.4', '8.5'], $jobs['distribution']['strategy']['matrix']['php']);

        foreach ([
            'package' => ['composer check', 'composer test:concurrency', 'composer benchmark:smoke'],
            'distribution' => ['composer check', 'composer smoke', 'composer consumer:redis', 'composer docs:check', 'composer package:check', 'composer upgrade:check'],
        ] as $identifier => $commands) {
            self::assertFalse($jobs[$identifier]['strategy']['fail-fast']);
            $executed = array_column($jobs[$identifier]['steps'], 'run');
            foreach ($commands as $command) {
                self::assertContains($command, $executed);
            }
        }
    }

    public function test_required_steps_cannot_ignore_failures(): void
    {
        $inspect = function (array $node) use (&$inspect): void {
            if (array_key_exists('continue-on-error', $node)) {
                self::assertFalse($node['continue-on-error']);
            }
            if (array_key_exists('cancel-in-progress', $node)) {
                self::assertFalse($node['cancel-in-progress']);
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $inspect($value);
                }
            }
        };
        foreach (['release.yml', 'tests.yml'] as $filename) {
            $workflow = $this->workflow($filename);
            $inspect($workflow);
            self::assertNotEmpty($workflow['jobs']);
        }
    }

    public function test_publisher_receives_exact_verification_results_and_identity(): void
    {
        $workflow = $this->workflow('release.yml');
        $steps = array_values(array_filter($workflow['jobs']['publish']['steps'], static fn (array $step): bool => isset($step['run'])));
        self::assertCount(1, $steps);
        self::assertSame('php tools/release.php', trim($steps[0]['run']));
        self::assertSame([
            'RELEASE_VERSION' => '${{ inputs.version }}',
            'RELEASE_COMMIT' => '${{ needs.prepare.outputs.commit }}',
            'RELEASE_VERIFICATION_RESULT' => '${{ needs.verify.result }}',
            'RELEASE_GATE_RESULT' => '${{ needs.release-acceptance.result }}',
            'GH_TOKEN' => '${{ github.token }}',
        ], $steps[0]['env']);
        foreach ($workflow['jobs'] as $identifier => $job) {
            foreach ($job['steps'] ?? [] as $step) {
                self::assertStringNotContainsString('${{ inputs.', $step['run'] ?? '', 'Release inputs belong in environment variables, not executable shell text.');
                if ($identifier !== 'publish') {
                    self::assertArrayNotHasKey('GH_TOKEN', $step['env'] ?? []);
                }
            }
        }
    }

    #[DataProvider('releaseRequests')]
    public function test_prepare_accepts_only_a_prerelease_for_the_current_main_workflow(array $overrides, bool $expectedSuccess): void
    {
        $job = $this->workflow('release.yml')['jobs']['prepare'];
        $steps = array_values(array_filter($job['steps'], static fn (array $step): bool => ($step['id'] ?? null) === 'request'));
        self::assertCount(1, $steps);
        self::assertSame('${{ steps.request.outputs.commit }}', $job['outputs']['commit']);
        self::assertSame([
            'RELEASE_VERSION' => '${{ inputs.version }}',
            'RELEASE_COMMIT' => '${{ inputs.commit }}',
            'RELEASE_WORKFLOW_COMMIT' => '${{ github.workflow_sha }}',
        ], $steps[0]['env']);
        [$exitCode, $output] = $this->executeShell('git rev-parse HEAD', []);
        self::assertSame(0, $exitCode);
        $commit = trim($output);
        $outputPath = tempnam(sys_get_temp_dir(), 'bulk-release-');
        self::assertIsString($outputPath);
        try {
            $environment = array_replace([
                'RELEASE_VERSION' => '0.1.0-beta.3',
                'RELEASE_COMMIT' => $commit,
                'RELEASE_WORKFLOW_COMMIT' => $commit,
                'GITHUB_SHA' => $commit,
                'GITHUB_REF' => 'refs/heads/main',
                'GITHUB_REPOSITORY' => 'gogoSpace/laravel-bulk-cache',
                'GITHUB_OUTPUT' => $outputPath,
            ], $overrides);
            [$exitCode, $output] = $this->executeShell($steps[0]['run'], $environment);
            self::assertSame($expectedSuccess, $exitCode === 0, $output);
            if ($expectedSuccess) {
                self::assertSame('commit='.$commit."\n", file_get_contents($outputPath));
            }
        } finally {
            unlink($outputPath);
        }
    }

    public static function releaseRequests(): array
    {
        return [
            'beta' => [[], true],
            'alpha' => [['RELEASE_VERSION' => '1.0.0-alpha.1'], true],
            'release candidate' => [['RELEASE_VERSION' => '2.0.0-rc.12'], true],
            'stable version' => [['RELEASE_VERSION' => '1.0.0'], false],
            'leading version zero' => [['RELEASE_VERSION' => '01.0.0-beta.1'], false],
            'empty prerelease identifier' => [['RELEASE_VERSION' => '1.0.0-beta..1'], false],
            'shell text in version' => [['RELEASE_VERSION' => '1.0.0-beta.1; exit 0'], false],
            'abbreviated commit' => [['RELEASE_COMMIT' => '1234567'], false],
            'nonhexadecimal commit' => [['RELEASE_COMMIT' => str_repeat('z', 40)], false],
            'different requested commit' => [['RELEASE_COMMIT' => str_repeat('a', 40)], false],
            'different event commit' => [['GITHUB_SHA' => str_repeat('a', 40)], false],
            'different workflow commit' => [['RELEASE_WORKFLOW_COMMIT' => str_repeat('a', 40)], false],
            'different checkout commit' => [['RELEASE_COMMIT' => str_repeat('a', 40), 'GITHUB_SHA' => str_repeat('a', 40), 'RELEASE_WORKFLOW_COMMIT' => str_repeat('a', 40)], false],
            'branch request' => [['GITHUB_REF' => 'refs/heads/prepare-release'], false],
            'tag request' => [['GITHUB_REF' => 'refs/tags/v0.1.0-beta.3'], false],
            'other repository' => [['GITHUB_REPOSITORY' => 'someone/laravel-bulk-cache'], false],
        ];
    }

    #[DataProvider('gateResults')]
    public function test_acceptance_scripts_reject_every_unsuccessful_prerequisite(string $filename, string $identifier, string $firstResult, string $secondResult): void
    {
        $job = $this->workflow($filename)['jobs'][$identifier];
        $dependencies = $job['needs'];
        self::assertCount(2, $dependencies);
        $steps = array_values(array_filter($job['steps'], static fn (array $step): bool => isset($step['run'])));
        self::assertCount(1, $steps, 'The final gate must make one complete decision.');
        $environment = ['GITHUB_STEP_SUMMARY' => '/dev/null', 'RELEASE_COMMIT' => str_repeat('a', 40), 'RELEASE_PUBLISH' => 'false'];
        foreach (array_combine($dependencies, [$firstResult, $secondResult]) as $dependency => $result) {
            $names = array_keys($steps[0]['env'], '${{ needs.'.$dependency.'.result }}', true);
            self::assertCount(1, $names, 'The gate must inspect the actual result of '.$dependency.'.');
            $environment[$names[0]] = $result;
        }
        [$exitCode, $output] = $this->executeShell($steps[0]['run'], $environment);
        $expectedSuccess = $firstResult === 'success' && $secondResult === 'success';
        self::assertSame($expectedSuccess, $exitCode === 0, $filename.' '.$firstResult.'/'.$secondResult.': '.$output);
    }

    public static function gateResults(): array
    {
        $cases = [];
        foreach (['tests.yml' => 'acceptance', 'release.yml' => 'release-acceptance'] as $filename => $identifier) {
            foreach (['success', 'failure', 'skipped', 'cancelled'] as $firstResult) {
                foreach (['success', 'failure', 'skipped', 'cancelled'] as $secondResult) {
                    $cases[$filename.' '.$firstResult.'/'.$secondResult] = [$filename, $identifier, $firstResult, $secondResult];
                }
            }
        }

        return $cases;
    }

    private function workflow(string $filename): array
    {
        return Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/'.$filename);
    }

    private function expression(string $expression): string
    {
        return trim(preg_replace('/^\$\{\{\s*|\s*\}\}$/', '', trim($expression)));
    }

    private function executeShell(string $script, array $environment): array
    {
        $process = proc_open(['bash', '--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), ['PATH' => (string) getenv('PATH')] + $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
