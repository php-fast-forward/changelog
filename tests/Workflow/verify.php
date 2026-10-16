<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** Fails a fixture assertion without allowing an external operation to recover it. */
function verifyWorkflow(bool $valid, string $message): void
{
    if (! $valid) {
        throw new RuntimeException($message);
    }
}

/** Executes actual workflow Bash guards with disposable outputs and forbidden daemon operations. */
function workflowGuard(string $source, string $directory, array $environment, int $expected): string
{
    $output = $directory . '/output-' . bin2hex(random_bytes(6));
    $process = new Process(['bash', '-c', $source], $directory, [
        'GH_TOKEN' => 'synthetic-fixture-token', 'GITHUB_TOKEN' => false,
        'GH_HOST' => false,
        'BASH_ENV' => $directory . '/daemon-mock', 'GITHUB_OUTPUT' => $output,
        ...$environment,
    ]);
    $process->setTimeout(10);
    $status = $process->run();
    verifyWorkflow($expected === $status, 'Workflow guard returned unexpected status: ' . $process->getErrorOutput());

    return is_file($output) ? file_get_contents($output) : '';
}

/** Executes the actual inline JavaScript with an isolated SDK double; errors must produce no outputs. */
function workflowScriptGuard(
    string $source,
    string $directory,
    array $environment,
    array $snapshot,
    int $expected,
): array {
    $harness = <<<'JS_WRAP'
    const outputs = {};
    const requests = [];
    const context = { repo: { owner: 'fixture', repo: 'consumer' } };
    const core = { setOutput: (name, value) => { outputs[name] = value; } };
    const github = { rest: { pulls: { get: async (request) => {
      requests.push(request);
      if (process.env.FF_FIXTURE_API_FAILURE === '1') throw new Error('Synthetic API failure');

      return { data: JSON.parse(process.argv[2]) };
    } } } };
    const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
    new AsyncFunction('github', 'context', 'core', process.argv[1])(github, context, core)
      .catch(() => { process.exitCode = 1; })
      .finally(() => process.stdout.write(JSON.stringify({ outputs, requests })));
    JS_WRAP;
    $process = new Process(['node', '-e', $harness, $source, json_encode($snapshot, JSON_THROW_ON_ERROR)], $directory, [
        'GH_TOKEN' => false, 'GITHUB_TOKEN' => false, 'GH_HOST' => false,
        ...$environment,
    ]);
    $process->setTimeout(10);
    $status = $process->run();
    verifyWorkflow($expected === $status, 'Workflow script returned unexpected status: ' . $process->getErrorOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    verifyWorkflow(0 === $expected || [] === $result['outputs'], 'Failed guards must never authorize an output.');
    foreach ($result['requests'] as $request) {
        verifyWorkflow(
            ['owner' => 'fixture', 'repo' => 'consumer', 'pull_number' => 7] === $request,
            'SDK requests must target the exact consumer PR.',
        );
    }

    return $result;
}

$root = dirname(__DIR__, 2);
$feedback = Yaml::parseFile($root . '/.github/workflows/changelog-pr-status.yml');
$lifecycle = Yaml::parseFile($root . '/.github/workflows/changelog.yml');
$internalDependabot = Yaml::parseFile($root . '/.github/workflows/dependabot-changelog.yml');
$assertions = 0;

// Every public entrypoint must preserve the caller's runner selection through nested jobs.
foreach (['changelog', 'changelog-check', 'changelog-dependabot', 'changelog-history',
    'changelog-pr-status', 'changelog-publish', 'changelog-version'] as $entrypoint) {
    $workflow = Yaml::parseFile($root . '/.github/workflows/' . $entrypoint . '.yml');
    $runner = $workflow['on']['workflow_call']['inputs']['runs-on'] ?? [];
    verifyWorkflow(
        'string' === ($runner['type'] ?? null) && '["ubuntu-latest"]' === ($runner['default'] ?? null),
        $entrypoint . ': caller-selectable runner with hosted default is required.',
    );
    ++$assertions;
    foreach ($workflow['jobs'] as $job) {
        verifyWorkflow(
            isset($job['uses'])
                ? '${{ inputs.runs-on }}' === ($job['with']['runs-on'] ?? null)
                : '${{ fromJSON(inputs.runs-on) }}' === ($job['runs-on'] ?? null),
            $entrypoint . ': every job must inherit the caller-selected runner.',
        );
        ++$assertions;
    }
}

// Resolve actual nested workflow contracts and verify that the caller never withholds a required permission.
foreach ([$feedback, $lifecycle, $internalDependabot] as $workflow) {
    foreach ($workflow['jobs'] as $name => $job) {
        if (! isset($job['uses'])) {
            continue;
        }
        verifyWorkflow(
            str_starts_with($job['uses'], './.github/workflows/'),
            'Nested runtime must resolve at the called revision.',
        );
        $called = Yaml::parseFile($root . '/' . substr($job['uses'], 2));
        $contract = $called['on']['workflow_call'];
        $provided = $job['with'] ?? [];
        $unknown = array_diff(array_keys($provided), array_keys($contract['inputs'] ?? []));
        verifyWorkflow([] === $unknown, $name . ': unknown inputs.');
        foreach ($contract['inputs'] ?? [] as $key => $input) {
            verifyWorkflow(
                ! ($input['required'] ?? false) || array_key_exists((string) $key, $provided),
                $name . ': missing ' . $key,
            );
        }
        foreach ($called['jobs'] as $child) {
            foreach ($child['permissions'] ?? [] as $permission => $level) {
                $ranks = ['none' => 0, 'read' => 1, 'write' => 2];
                verifyWorkflow(
                    $ranks[$job['permissions'][$permission] ?? 'none'] >= $ranks[$level],
                    $name . ': withheld permission ' . $permission,
                );
            }
        }
        ++$assertions;
    }
}

$internalFragment = $internalDependabot['jobs']['fragment'];
$internalFeedback = $internalDependabot['jobs']['feedback'];
verifyWorkflow(
    '${{ github.workflow_sha }}' === $internalFragment['steps'][0]['with']['ref']
        && '${{ github.repository }}' === $internalFragment['steps'][0]['with']['repository']
        && false === $internalFragment['steps'][0]['with']['persist-credentials'],
    'Privileged Dependabot runtime must use the trusted workflow commit, never a stale PR base snapshot.',
);
verifyWorkflow(
    '${{ steps.operation.outputs.head-sha }}' === $internalFragment['outputs']['head-sha']
        && 'operation' === $internalFragment['steps'][3]['id']
        && './.github/actions/dependabot' === $internalFragment['steps'][3]['uses'],
    'Internal Dependabot must forward the fragment action head for same-run feedback.',
);
verifyWorkflow(
    'fragment' === $internalFeedback['needs'] && "needs.fragment.result == 'success'" === $internalFeedback['if'],
    'Internal feedback must run after successful creation and unchanged idempotent results.',
);
verifyWorkflow(
    '${{ needs.fragment.outputs.head-sha || github.event.pull_request.head.sha }}'
        === $internalFeedback['with']['head-sha']
        && '${{ github.event.pull_request.number }}' === $internalFeedback['with']['pull-request'],
    'Internal feedback must validate the new fragment head or the unchanged immutable event head.',
);
verifyWorkflow(
    ! array_key_exists('base-sha', $internalFeedback['with']),
    'Internal feedback must resolve the live API base instead of trusting a stale PR payload snapshot.',
);
verifyWorkflow(
    'always' === $internalFeedback['with']['comment-mode']
        && '${{ secrets.CHANGELOG_TOKEN || github.token }}' === $internalFeedback['secrets']['token'],
    'Internal feedback must update its sticky comment using the existing trusted token contract.',
);
$assertions += 6;

foreach ($feedback['jobs']['status']['steps'] as $step) {
    if (! str_starts_with($step['uses'] ?? '', './.changelog-runtime/.github/actions/')) {
        continue;
    }
    $action = Yaml::parseFile($root . str_replace('./.changelog-runtime', '', $step['uses']) . '/action.yml');
    $provided = $step['with'] ?? [];
    $unknown = array_diff(array_keys($provided), array_keys($action['inputs']));
    verifyWorkflow([] === $unknown, 'Feedback uses an unknown action input.');
    foreach ($action['inputs'] as $key => $input) {
        verifyWorkflow(
            ! ($input['required'] ?? false) || array_key_exists((string) $key, $provided),
            'Feedback omits a required action input.',
        );
    }
    ++$assertions;
}

verifyWorkflow(
    false === $lifecycle['on']['workflow_call']['inputs']['publish']['default'],
    'Publication must remain opt-in during adoption.',
);
verifyWorkflow(
    'always' === $lifecycle['on']['workflow_call']['inputs']['comment-mode']['default'],
    'Feedback must default to one updated comment.',
);
$comment = $feedback['jobs']['comment'];
verifyWorkflow(str_contains($comment['if'], '!cancelled()'), 'Failed validation must not suppress the feedback job.');
verifyWorkflow(
    isset($comment['steps'][1]['with']['only_update']),
    'Recovery in on-failure mode must update an existing failed comment.',
);
verifyWorkflow(
    str_contains($comment['steps'][1]['with']['message'], 'Validation could not complete'),
    'Operational failures need controlled fallback feedback.',
);
verifyWorkflow(
    'exit 1' === $feedback['jobs']['status']['steps'][5]['run'],
    'Feedback rendering must retain the failing contribution gate.',
);
$assertions += 6;

$filesystem = new Filesystem();
$directory = sys_get_temp_dir() . '/changelog-workflow-' . bin2hex(random_bytes(8));
$filesystem->mkdir($directory);

try {
    $filesystem->dumpFile($directory . '/daemon-mock', <<<'BASH'
        if [[ "${FF_FIXTURE_FORBID_DOCKER:-}" == 1 ]]; then
          sudo() { touch unexpected-daemon-operation; return 73; }
          docker() { touch unexpected-daemon-operation; return 73; }
          jq() { touch unexpected-daemon-operation; return 73; }
        fi
        BASH);
    $cache = Yaml::parseFile($root . '/.github/actions/setup-docker/action.yml');
    workflowGuard($cache['runs']['steps'][0]['run'], $directory, [
        'RUNNER_ENVIRONMENT' => 'self-hosted', 'RUNNER_OS' => 'Linux', 'FF_FIXTURE_FORBID_DOCKER' => '1',
    ], 0);
    verifyWorkflow(
        ! is_file($directory . '/unexpected-daemon-operation'),
        'Self-hosted daemons must remain untouched.',
    );
    workflowGuard($cache['runs']['steps'][0]['run'], $directory, [
        'RUNNER_ENVIRONMENT' => 'github-hosted', 'RUNNER_OS' => 'Windows', 'FF_FIXTURE_FORBID_DOCKER' => '1',
    ], 1);
    verifyWorkflow(
        "runner.environment == 'github-hosted'" === ($cache['runs']['steps'][1]['if'] ?? null),
        'Base-image preloading must stay restricted to the hosted cache.',
    );
    $assertions += 3;
    $head = str_repeat('a', 40);
    $base = str_repeat('b', 40);
    $environment = [
        'FF_RUNTIME_REPOSITORY' => 'php-fast-forward/changelog', 'FF_RUNTIME_SHA' => str_repeat('c', 40),
        'FF_EVENT' => 'pull_request_target', 'FF_REF' => 'refs/heads/main',
        'FF_EVENT_PR' => '7', 'FF_PR' => '7', 'FF_HEAD' => $head,
        'FF_BASE' => $base, 'FF_BRANCH' => 'main', 'FF_COMMENT_MODE' => 'always',
    ];
    $pr = ['state' => 'open', 'head' => ['sha' => $head], 'base' => ['sha' => $base, 'ref' => 'main']];
    $guard = $feedback['jobs']['status']['steps'][0]['with']['script'];
    foreach ([
        [[], 0], [['FF_BASE' => ''], 0], [['FF_COMMENT_MODE' => 'on-failure'], 0],
        [['FF_COMMENT_MODE' => 'never'], 0], [['FF_EVENT' => 'push'], 0],
        [['FF_EVENT' => 'workflow_dispatch'], 0], [['FF_BRANCH' => 'master', 'FF_REF' => 'refs/heads/master'], 0],
        [['FF_EVENT' => 'pull_request'], 1], [['FF_EVENT_PR' => '8'], 1],
        [['FF_EVENT' => 'push', 'FF_REF' => 'refs/heads/feature'], 1],
        [['FF_EVENT' => 'workflow_dispatch', 'FF_REF' => 'refs/heads/feature'], 1],
        [['FF_RUNTIME_SHA' => 'main'], 1], [['FF_PR' => '7; touch unexpected'], 1],
        [['FF_HEAD' => 'moving-branch'], 1], [['FF_BASE' => 'short'], 1],
        [['FF_COMMENT_MODE' => 'sometimes'], 1], [['FF_RUNTIME_REPOSITORY' => ''], 1],
        [['FF_PR' => '9007199254740993'], 1], [['FF_PR' => '0'], 1],
        [['FF_PR' => '7\n'], 1],
        [['FF_BASE' => '', 'FF_FIXTURE_API_FAILURE' => '1'], 1],
    ] as [$overrides, $expected]) {
        $result = workflowScriptGuard($guard, $directory, [...$environment, ...$overrides], $pr, $expected);
        verifyWorkflow(
            0 !== $expected || ['base' => $base] === $result['outputs'],
            'Resolved base output must be an exact immutable SHA.',
        );
        verifyWorkflow(
            count($result['requests']) === ('' === ($overrides['FF_BASE'] ?? $base) ? 1 : 0),
            'Explicit bases and rejected identities must not query the API.',
        );
        ++$assertions;
    }
    workflowScriptGuard($guard, $directory, [...$environment, 'FF_BASE' => ''],
        [...$pr, 'base' => ['sha' => 'main', 'ref' => 'main']], 1);
    verifyWorkflow(! is_file($directory . '/unexpected'), 'PR metadata must never become executable shell text.');
    $guard = $comment['steps'][0]['with']['script'];
    foreach ([
        [$pr, $base, true], [$pr, '', true],
        [[...$pr, 'state' => 'closed'], $base, false],
        [[...$pr, 'head' => ['sha' => str_repeat('d', 40)]], $base, false],
        [[...$pr, 'base' => ['sha' => str_repeat('d', 40), 'ref' => 'main']], $base, false],
        [[...$pr, 'base' => ['sha' => $base, 'ref' => 'other']], $base, false],
    ] as [$snapshot, $expectedBase, $current]) {
        $result = workflowScriptGuard($guard, $directory, [...$environment, 'FF_BASE' => $expectedBase], $snapshot, 0);
        verifyWorkflow(
            ($current ? ['current' => 'true'] : []) === $result['outputs'],
            'Moved or closed PR feedback must not overwrite a newer comment.',
        );
        ++$assertions;
    }
    foreach ([['FF_PR' => '7; touch unexpected'], ['FF_PR' => '9007199254740993'],
        ['FF_HEAD' => 'moving-branch'], ['FF_BASE' => 'main'], ['FF_FIXTURE_API_FAILURE' => '1']] as $overrides) {
        workflowScriptGuard($guard, $directory, [...$environment, ...$overrides], $pr, 1);
        ++$assertions;
    }
    foreach ([$feedback['jobs']['status']['steps'][0], $comment['steps'][0]] as $step) {
        verifyWorkflow(
            'actions/github-script@v9.0.0' === $step['uses']
                && '${{ secrets.token || github.token }}' === $step['with']['github-token']
                && ! str_contains($step['with']['script'], '${{'),
            'SDK guards must use the verified action version and inert environment input.',
        );
        ++$assertions;
    }
    workflowGuard($feedback['jobs']['status']['steps'][5]['run'], $directory, [], 1);
    $assertions += 3;
} finally {
    $filesystem->remove($directory);
}

echo json_encode(['status' => 'passed', 'workflow_cases' => $assertions, 'network_requests' => 0,
    'publication' => false, 'consumer_installation' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
