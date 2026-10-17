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

/** Selects a named step so adding authentication cannot silently redirect a fixture to another operation. */
function workflowStep(array $job, string $name): array
{
    foreach ($job['steps'] as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    throw new RuntimeException('Missing workflow step: ' . $name);
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
    foreach (['FF_AUTH_PRIVATE_KEY', 'FF_AUTH_TOKEN'] as $secret) {
        verifyWorkflow(
            '' === ($environment[$secret] ?? '') || ! str_contains(
                $process->getOutput() . $process->getErrorOutput(),
                $environment[$secret],
            ),
            'Authentication diagnostics must not reveal a credential.',
        );
    }

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

// This pinned action reads optional lookup flags as strings: even the literal "false" enables a lookup.
foreach ([$internalDependabot, Yaml::parseFile($root . '/.github/workflows/changelog-dependabot.yml')] as $workflow) {
    foreach ($workflow['jobs'] as $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if (($step['uses'] ?? null) !== 'dependabot/fetch-metadata@v2.5.0') {
                continue;
            }
            verifyWorkflow(
                '' === ($step['with']['alert-lookup'] ?? '') && '' === ($step['with']['compat-lookup'] ?? '')
                    && 'false' === $step['with']['skip-verification']
                    && 'false' === $step['with']['skip-commit-verification'],
                'Dependabot metadata must omit truthy lookup flags while retaining origin and commit verification.',
            );
            ++$assertions;
        }
    }
}

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
$versionWorkflow = Yaml::parseFile($root . '/.github/workflows/changelog-version.yml');
$versionAction = Yaml::parseFile($root . '/.github/actions/version/action.yml');
verifyWorkflow(
    'chore: update changelog' === $lifecycle['on']['workflow_call']['inputs']['version-pr-title']['default']
        && '${{ inputs.version-pr-title }}' === $lifecycle['jobs']['version']['with']['title']
        && 'chore: update changelog' === $versionWorkflow['on']['workflow_call']['inputs']['title']['default']
        && '${{ inputs.title }}' === workflowStep(
            $versionWorkflow['jobs']['changelog'],
            'Run The Shared Version Action',
        )['with']['title']
        && in_array('--title=${{ inputs.title }}', $versionAction['runs']['args'], true),
    'The facade must forward the optional title through the existing workflow, action and CLI without changing defaults.',
);
++$assertions;
$comment = $feedback['jobs']['comment'];
$sticky = workflowStep($comment, 'Create Or Update One Changelog Comment');
$preserveFailure = workflowStep($feedback['jobs']['status'], 'Preserve The Contribution Gate Failure');
verifyWorkflow(str_contains($comment['if'], '!cancelled()'), 'Failed validation must not suppress the feedback job.');
verifyWorkflow(
    isset($sticky['with']['only_update']),
    'Recovery in on-failure mode must update an existing failed comment.',
);
verifyWorkflow(
    str_contains($sticky['with']['message'], 'Validation could not complete'),
    'Operational failures need controlled fallback feedback.',
);
verifyWorkflow(
    'exit 1' === $preserveFailure['run'],
    'Feedback rendering must retain the failing contribution gate.',
);
$assertions += 6;

$auth = Yaml::parseFile($root . '/.github/actions/github-auth/action.yml');
$mint = $auth['runs']['steps'][1];
verifyWorkflow(
    'actions/create-github-app-token@v3.3.0' === $mint['uses']
        && "steps.scope.outputs.app == 'true'" === $mint['if']
        && 'false' === $mint['with']['skip-token-revoke']
        && '${{ steps.scope.outputs.owner }}' === $mint['with']['owner']
        && '${{ steps.scope.outputs.repository }}' === $mint['with']['repositories'],
    'App tokens must use the verified release, one explicit repository and automatic revocation.',
);
verifyWorkflow(
    ['token'] === array_keys($auth['outputs'])
        && '${{ steps.app.outputs.token || inputs.token || inputs.fallback-token }}' === $auth['outputs']['token']['value'],
    'Authentication must preserve raw-token and built-in fallback without persisting credentials.',
);
$check = Yaml::parseFile($root . '/.github/workflows/changelog-check.yml');
verifyWorkflow(
    ! isset($check['on']['workflow_call']['inputs']['app-client-id'])
        && ! isset($check['on']['workflow_call']['secrets']['app-private-key'])
        && ! isset($lifecycle['jobs']['check']['with']['app-client-id'])
        && ! isset($lifecycle['jobs']['check']['secrets']),
    'Untrusted PR-head checks must never receive App credentials.',
);
$assertions += 3;
foreach (['changelog', 'changelog-dependabot', 'changelog-history', 'changelog-pr-status',
    'changelog-publish', 'changelog-version'] as $entrypoint) {
    $workflow = Yaml::parseFile($root . '/.github/workflows/' . $entrypoint . '.yml');
    $contract = $workflow['on']['workflow_call'];
    verifyWorkflow(
        'string' === $contract['inputs']['app-client-id']['type']
            && '' === $contract['inputs']['app-client-id']['default']
            && false === $contract['secrets']['app-private-key']['required'],
        $entrypoint . ': App authentication must remain optional.',
    );
    foreach ($workflow['jobs'] as $name => $job) {
        if ('changelog' === $entrypoint) {
            if ('check' !== $name) {
                verifyWorkflow(
                    '${{ inputs.app-client-id }}' === $job['with']['app-client-id']
                        && '${{ secrets.app-private-key }}' === $job['secrets']['app-private-key']
                        && '${{ secrets.token }}' === $job['secrets']['token'],
                    $name . ': forward only raw optional credentials; do not mix the fallback token with App mode.',
                );
            }
        } else {
            $authentication = workflowStep($job, 'Authenticate Trusted GitHub Operations');
            $runtime = workflowStep($job, 'Check Out The Called Workflow Runtime');
            $authenticationPosition = array_search($authentication, $job['steps'], true);
            $operation = 'changelog-pr-status' === $entrypoint ? $name : substr($entrypoint, strlen('changelog-'));
            verifyWorkflow(
                '${{ inputs.app-client-id }}' === $authentication['with']['app-client-id']
                    && '${{ secrets.app-private-key }}' === $authentication['with']['app-private-key']
                    && '${{ secrets.token }}' === $authentication['with']['token']
                    && '${{ github.token }}' === $authentication['with']['fallback-token']
                    && $operation === $authentication['with']['operation']
                    && './.changelog-runtime/.github/actions/github-auth' === $authentication['uses']
                    && '${{ fromJSON(toJSON(job)).workflow_sha }}' === $runtime['with']['ref']
                    && false === $runtime['with']['persist-credentials']
                    && array_search($runtime, $job['steps'], true) < $authenticationPosition,
                $entrypoint . ': authentication must execute only from the checked-out immutable trusted runtime.',
            );
            foreach ($job['steps'] as $step) {
                if (($step['with']['path'] ?? null) === 'project') {
                    verifyWorkflow(
                        '${{ steps.auth.outputs.token }}' === $step['with']['token']
                            && false === $step['with']['persist-credentials']
                            && $authenticationPosition < array_search($step, $job['steps'], true),
                        $entrypoint . ': consumer checkout must use the selected token without persisting it.',
                    );
                }
                if (($step['id'] ?? null) === 'operation') {
                    verifyWorkflow(
                        '${{ steps.auth.outputs.token }}' === $step['with']['token']
                            && $authenticationPosition < array_search($step, $job['steps'], true),
                        $entrypoint . ': remote operations must use this job\'s selected token.',
                    );
                }
            }
        }
        verifyWorkflow(
            ! str_contains(json_encode($job['outputs'] ?? []), 'auth.outputs.token')
                && ! str_contains(json_encode($workflow['on']['workflow_call']['outputs'] ?? []), 'private-key'),
            $entrypoint . ': credentials must never cross a job/workflow output boundary.',
        );
        ++$assertions;
    }
    ++$assertions;
}
verifyWorkflow(
    '${{ steps.auth.outputs.token }}' === $sticky['with']['GITHUB_TOKEN']
        && 'comment' === workflowStep($comment, 'Authenticate Trusted GitHub Operations')['with']['operation'],
    'Sticky feedback must mint/select its own job-local token.',
);
++$assertions;

$filesystem = new Filesystem();
$directory = sys_get_temp_dir() . '/changelog-workflow-' . bin2hex(random_bytes(8));
$filesystem->mkdir($directory);

try {
    $authenticationEnvironment = [
        'FF_AUTH_CLIENT_ID' => '', 'FF_AUTH_PRIVATE_KEY' => '', 'FF_AUTH_TOKEN' => '',
        'FF_AUTH_REPOSITORY' => 'fixture/consumer', 'FF_AUTH_OPERATION' => 'version',
        'FF_AUTH_READ_ALERTS' => 'false',
        'FF_AUTH_BRANCH' => 'main', 'FF_AUTH_EVENT' => 'push', 'FF_AUTH_REF' => 'refs/heads/main',
        'FF_AUTH_ACTION' => 'opened', 'FF_AUTH_AUTHOR' => 'dependabot[bot]',
        'FF_AUTH_HEAD_REPOSITORY' => 'fixture/consumer', 'FF_AUTH_MERGED' => 'true',
    ];
    $appCredentials = ['FF_AUTH_CLIENT_ID' => 'Iv23.fixture', 'FF_AUTH_PRIVATE_KEY' => 'synthetic-private-key'];
    foreach ([
        [[], 0, false], [['FF_AUTH_TOKEN' => 'synthetic-existing-token'], 0, false],
        [$appCredentials, 0, true],
        [['FF_AUTH_CLIENT_ID' => 'Iv23.fixture'], 1, false],
        [['FF_AUTH_PRIVATE_KEY' => 'synthetic-private-key'], 1, false],
        [[...$appCredentials, 'FF_AUTH_TOKEN' => 'synthetic-existing-token'], 1, false],
        [[...$appCredentials, 'FF_AUTH_EVENT' => 'pull_request'], 1, false],
        [[...$appCredentials, 'FF_AUTH_REF' => 'refs/heads/feature'], 1, false],
        [[...$appCredentials, 'FF_AUTH_REPOSITORY' => "fixture/consumer\nowner=attacker"], 1, false],
        [[...$appCredentials, 'FF_AUTH_REPOSITORY' => 'fixture/consumer,other'], 1, false],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'unknown'], 1, false],
        [[...$appCredentials, 'FF_AUTH_READ_ALERTS' => 'true'], 1, false],
        [[...$appCredentials, 'FF_AUTH_READ_ALERTS' => 'invalid'], 1, false],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'status', 'FF_AUTH_EVENT' => 'pull_request_target'], 0, true],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'comment', 'FF_AUTH_EVENT' => 'pull_request_target'], 0, true],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'comment', 'FF_AUTH_EVENT' => 'pull_request_target', 'FF_AUTH_ACTION' => 'closed'], 1, false],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'dependabot', 'FF_AUTH_EVENT' => 'pull_request_target'], 0, true],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'dependabot', 'FF_AUTH_EVENT' => 'pull_request_target', 'FF_AUTH_AUTHOR' => 'human'], 1, false],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'dependabot', 'FF_AUTH_EVENT' => 'pull_request_target', 'FF_AUTH_HEAD_REPOSITORY' => 'fork/consumer'], 1, false],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'publish', 'FF_AUTH_EVENT' => 'pull_request_target', 'FF_AUTH_ACTION' => 'closed'], 0, true],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'publish', 'FF_AUTH_EVENT' => 'pull_request_target', 'FF_AUTH_ACTION' => 'closed', 'FF_AUTH_MERGED' => 'false'], 1, false],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'publish', 'FF_AUTH_EVENT' => 'pull_request_target'], 1, false],
        [[...$appCredentials, 'FF_AUTH_OPERATION' => 'history', 'FF_AUTH_EVENT' => 'workflow_dispatch'], 0, true],
    ] as [$overrides, $expected, $appEnabled]) {
        $output = workflowGuard($auth['runs']['steps'][0]['run'], $directory, [
            ...$authenticationEnvironment, ...$overrides,
        ], $expected);
        verifyWorkflow(
            0 === $expected ? str_contains($output, 'app=' . ($appEnabled ? 'true' : 'false') . "\n") : '' === $output,
            'Rejected credentials/events must produce no scope capable of authorizing a token.',
        );
        verifyWorkflow(! str_contains($output, 'synthetic-'), 'Scope outputs must contain no credential bytes.');
        ++$assertions;
    }
    foreach ([
        'version' => ['write', 'write', 'read', ''],
        'dependabot' => ['write', 'read', '', ''],
        'status' => ['read', 'read', 'read', 'write'],
        'comment' => ['read', 'write', '', ''],
        'publish' => ['write', 'read', '', ''],
        'history' => ['read', '', '', ''],
    ] as $operation => $permissions) {
        $event = match ($operation) {
            'dependabot', 'status', 'comment', 'publish' => 'pull_request_target',
            'history' => 'workflow_dispatch',
            default => 'push',
        };
        foreach ([false, true] as $appEnabled) {
            $output = workflowGuard($auth['runs']['steps'][0]['run'], $directory, [
                ...$authenticationEnvironment, ...($appEnabled ? $appCredentials : []),
                'FF_AUTH_OPERATION' => $operation, 'FF_AUTH_EVENT' => $event,
                'FF_AUTH_ACTION' => 'publish' === $operation ? 'closed' : 'opened',
            ], 0);
            verifyWorkflow(
                str_contains($output, 'app=' . ($appEnabled ? 'true' : 'false') . "\n"),
                'Permission cases must exercise both fallback and enabled App authentication.',
            );
            $permissionNames = ['contents', 'pull-requests', 'issues', 'statuses'];
            foreach (array_combine($permissionNames, $permissions) as $permission => $level) {
                verifyWorkflow(
                    str_contains($output, $permission . '=' . $level . "\n"),
                    'App permissions must match the exact operation.',
                );
                verifyWorkflow(
                    '${{ steps.scope.outputs.' . $permission . ' }}' === $mint['with']['permission-' . $permission],
                    'Permission scope must reach the official token action.',
                );
            }
            verifyWorkflow(
                str_contains($output, "owner=fixture\nrepository=consumer\n"),
                'Tokens must target only the selected repository.',
            );
            verifyWorkflow(
                str_contains($output, "vulnerability-alerts=\n"),
                'Default operations must not request access to Dependabot alerts.',
            );
            ++$assertions;
        }
    }
    foreach (['false' => '', 'true' => 'read'] as $requested => $permission) {
        $output = workflowGuard($auth['runs']['steps'][0]['run'], $directory, [
            ...$authenticationEnvironment, ...$appCredentials,
            'FF_AUTH_OPERATION' => 'dependabot', 'FF_AUTH_EVENT' => 'pull_request_target',
            'FF_AUTH_READ_ALERTS' => $requested,
        ], 0);
        verifyWorkflow(
            str_contains($output, 'vulnerability-alerts=' . $permission . "\n")
                && '${{ steps.scope.outputs.vulnerability-alerts }}' === $mint['with']['permission-vulnerability-alerts'],
            'Only explicitly requested Dependabot alert evidence may add read permission.',
        );
        ++$assertions;
    }
    $dependabotWorkflow = Yaml::parseFile($root . '/.github/workflows/changelog-dependabot.yml');
    $dependabotAuth = workflowStep($dependabotWorkflow['jobs']['changelog'], 'Authenticate Trusted GitHub Operations');
    verifyWorkflow(
        '[]' === $dependabotWorkflow['on']['workflow_call']['inputs']['security-alert-numbers']['default']
            && '${{ inputs.security-alert-numbers != \'\' && inputs.security-alert-numbers != \'[]\' }}'
                === $dependabotAuth['with']['read-dependabot-alerts']
            && ! isset($lifecycle['jobs']['dependabot']['with']['security-alert-numbers']),
        'The facade must retain no alert grant; the individual workflow opts in only for explicit alert metadata.',
    );
    ++$assertions;
    foreach ([['fixture-app[bot]', 'fixture-app', 0], ['github-actions[bot]', 'fixture-app', 1],
        ['fixture-app[bot]', '', 1], ['$(touch unexpected)[bot]', 'fixture-app', 1]] as [$actor, $slug, $expected]) {
        workflowGuard($auth['runs']['steps'][2]['run'], $directory, [
            'FF_AUTH_ACTOR' => $actor, 'FF_AUTH_APP_SLUG' => $slug,
        ], $expected);
        ++$assertions;
    }
    verifyWorkflow(! is_file($directory . '/unexpected'), 'Bot login must remain literal data.');
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
    workflowGuard($preserveFailure['run'], $directory, [], 1);
    $assertions += 3;
} finally {
    $filesystem->remove($directory);
}

echo json_encode(['status' => 'passed', 'workflow_cases' => $assertions, 'network_requests' => 0,
    'publication' => false, 'consumer_installation' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
