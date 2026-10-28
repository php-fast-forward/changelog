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
    } }, repos: {
      listTags: () => {}
    } }, paginate: async (method, request) => {
      requests.push(request);
      if (process.env.FF_FIXTURE_API_FAILURE === '1') throw new Error('Synthetic API failure');
      return JSON.parse(process.argv[2]).tags;
    } };
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
            ['owner' => 'fixture', 'repo' => 'consumer', ...match (true) {
                isset($request['per_page']) => ['per_page' => 100],
                default => ['pull_number' => 7],
            }] === $request,
            'SDK requests must target the exact consumer PR.',
        );
    }

    return $result;
}

$root = dirname(__DIR__, 2);
$feedback = Yaml::parseFile($root . '/.github/workflows/changelog-pr-status.yml');
$lifecycle = Yaml::parseFile($root . '/.github/workflows/changelog.yml');
$repository = Yaml::parseFile($root . '/.github/workflows/repository-changelog.yml');
$repositoryCi = Yaml::parseFile($root . '/.github/workflows/repository-ci.yml');
$repositoryImage = Yaml::parseFile($root . '/.github/workflows/repository-image.yml');
$repositoryDocumentation = Yaml::parseFile($root . '/.github/workflows/repository-documentation.yml');
$assertions = 0;

require __DIR__ . '/IntegrationDiagnosticsFixture.php';
$assertions += IntegrationDiagnosticsFixture::verify($root, $repositoryCi['jobs']['tests']);
require __DIR__ . '/WindowsPartitionFixture.php';
$assertions += WindowsPartitionFixture::verify($root, $repositoryCi['jobs']);

$consumerData = workflowStep($feedback['jobs']['status'], 'Check Out Consumer Data Without Executing It');
verifyWorkflow(
    'actions/checkout@v7.0.1' === $consumerData['uses']
        && true === ($consumerData['with']['allow-unsafe-pr-checkout'] ?? false)
        && '${{ github.repository }}' === $consumerData['with']['repository']
        && '${{ inputs.head-sha }}' === $consumerData['with']['ref']
        && 'project' === $consumerData['with']['path']
        && false === $consumerData['with']['persist-credentials']
        && false === ($consumerData['with']['submodules'] ?? null)
        && false === ($consumerData['with']['lfs'] ?? null),
    'Checkout v7 must permit immutable fork data without enabling credentials, submodules or LFS.',
);
++$assertions;
foreach ($feedback['jobs']['status']['steps'] as $step) {
    if (isset($step['uses'])) {
        verifyWorkflow(
            in_array($step['uses'], [
                'actions/github-script@v9.0.0', 'actions/checkout@v7.0.1',
                './.changelog-runtime/.github/actions/github-auth',
                './.changelog-runtime/.github/actions/setup-docker',
                './.changelog-runtime/.github/actions/pr-status',
            ], true),
            'Fork data must never introduce a consumer action or an unreviewed executable dependency.',
        );
        ++$assertions;
    }
    if (isset($step['run']) || isset($step['with']['script'])) {
        verifyWorkflow(
            in_array($step['name'], [
                'Resolve Trusted Context And Immutable Comparison', 'Preserve The Contribution Gate Failure',
            ], true),
            'Only the trusted snapshot guard and gate-result step may execute inline code in the fork-data job.',
        );
        ++$assertions;
    }
}
foreach (glob($root . '/.github/workflows/*.yml') as $path) {
    foreach (Yaml::parseFile($path)['jobs'] as $jobName => $job) {
        foreach ($job['steps'] ?? [] as $step) {
            if ($step['with']['allow-unsafe-pr-checkout'] ?? false) {
                verifyWorkflow(
                    'changelog-pr-status.yml' === basename($path)
                        && 'status' === $jobName && $consumerData === $step,
                    'The fork-checkout opt-in must never spread beyond the trusted data-validation step.',
                );
                ++$assertions;
            }
        }
    }
}

foreach ([
    'changelog',
    'changelog-check',
    'changelog-pr-status',
    'changelog-version',
    'changelog-publish',
] as $workflowName) {
    $workflow = Yaml::parseFile($root . '/.github/workflows/' . $workflowName . '.yml');
    $referenceInput = $workflow['on']['workflow_call']['inputs']['resolve-pull-requests'];
    verifyWorkflow(
        'boolean' === $referenceInput['type'] && true === $referenceInput['default'],
        $workflowName . ' must expose the enabled reference policy as a boolean.',
    );
    ++$assertions;
}

foreach (['check', 'feedback', 'version', 'validate-version', 'publish'] as $jobName) {
    verifyWorkflow(
        '${{ inputs.resolve-pull-requests }}' === $lifecycle['jobs'][$jobName]['with']['resolve-pull-requests'],
        $jobName . ' must receive the same release-evidence reference policy.',
    );
    ++$assertions;
}

foreach (['check', 'pr-status', 'version', 'publish'] as $actionName) {
    $action = Yaml::parseFile($root . '/.github/actions/' . $actionName . '/action.yml');
    verifyWorkflow(
        'true' === $action['inputs']['resolve-pull-requests']['default']
            && in_array('--resolve-pull-requests=${{ inputs.resolve-pull-requests }}', $action['runs']['args'], true),
        $actionName . ' must pass its reference policy directly to the CLI.',
    );
    ++$assertions;
}

verifyWorkflow(
    'Repository: Tests' === $repositoryCi['name']
        && $repositoryCi['name'] === workflowStep(
            $repositoryCi['jobs']['coverage-report'],
            'Compare And Summarize Line Coverage',
        )['with']['artifact_download_workflow_names'],
    'Coverage must resolve artifacts from the renamed repository CI workflow.',
);
verifyWorkflow(
    'Fresh Composer Installations (${{ matrix.dependencies }})' === $repositoryCi['jobs']['packaging']['name']
        && ['highest', 'lowest'] === $repositoryCi['jobs']['packaging']['strategy']['matrix']['dependencies']
        && '${{ matrix.check }}' === $repositoryCi['jobs']['tests']['name']
        && ['PHP 8.5 (ubuntu-latest)', 'PHP 8.5 (ubuntu-24.04-arm)'] === array_column(
            array_filter($repositoryCi['jobs']['tests']['strategy']['matrix']['include'],
                static fn(array $row): bool => 'windows-latest' !== $row['os']),
            'check',
        )
        && 'PHP 8.5 (windows-latest)' === $repositoryCi['jobs']['windows']['name'],
    'Repository CI renames must preserve all five required check names and matrix values.',
);
verifyWorkflow(
    'Repository: Publish Docker CLI' === $repositoryImage['name']
        && ['workflow_dispatch', 'workflow_call'] === array_keys($repositoryImage['on'])
        && true === $repositoryImage['on']['workflow_call']['inputs']['tag']['required']
        && true === $repositoryImage['on']['workflow_call']['inputs']['expected-sha']['required']
        && true === $repositoryImage['on']['workflow_dispatch']['inputs']['tag']['required']
        && true === $repositoryImage['on']['workflow_dispatch']['inputs']['expected-sha']['required']
        && './.github/workflows/repository-image.yml' === $repository['jobs']['image']['uses'],
    'Image calls and explicit recovery must require the existing release tag and approved source selector.',
);
$assertions += 3;

$imageReleaseGuard = workflowStep($repositoryImage['jobs']['validate'], 'Verify The Published GitHub Release');
verifyWorkflow(
    ['contents' => 'read'] === $repositoryImage['jobs']['validate']['permissions']
        && '${{ inputs.expected-sha }}' === $imageReleaseGuard['env']['FF_EXPECTED_SHA']
        && '${{ steps.release.outputs.tag }}' === $imageReleaseGuard['env']['FF_TAG'],
    'Image recovery must validate an existing published release with a read-only token before building.',
);
foreach (['Prepare The Multi-Platform Release Image', 'Publish The Prepared Release Image'] as $stepName) {
    $step = workflowStep($repositoryImage['jobs']['publish'], $stepName);
    verifyWorkflow(
        ! isset($step['with']['cache-to']) && ! isset($step['with']['cache-from']),
        'Release images must not depend on external cache exports in read-only cache events.',
    );
}
$assertions += 3;

verifyWorkflow(
    [
        'pull_request' => [
            'branches' => ['main'],
            'types' => ['opened', 'synchronize', 'reopened', 'ready_for_review', 'labeled', 'unlabeled'],
        ],
        'pull_request_target' => ['branches' => ['main'], 'types' => ['opened', 'synchronize', 'reopened', 'closed']],
        'push' => ['branches' => ['main']],
    ] === $repository['on'],
    'The internal caller must preserve the original event union without a new publication trigger.',
);
$repositoryCheck = $repository['jobs']['check'];
$repositoryLifecycle = $repository['jobs']['changelog'];
verifyWorkflow(
    'Repository: Changelog' === $repository['name']
        && 'Check Contribution' === $repositoryCheck['name']
        && 'Check Changelog' === Yaml::parseFile(
            $root . '/.github/workflows/changelog-check.yml',
        )['jobs']['changelog']['name']
        && './.github/workflows/changelog-check.yml' === $repositoryCheck['uses']
        && "github.event_name == 'pull_request'" === $repositoryCheck['if']
        && ['contents' => 'read', 'pull-requests' => 'read', 'issues' => 'read'] === $repositoryCheck['permissions']
        && ! isset($repositoryCheck['secrets']),
    'The PR route must preserve its exact required context, read-only token and direct modular call without secrets.',
);
verifyWorkflow(
    './.github/workflows/changelog.yml' === $repositoryLifecycle['uses']
        && "github.event_name != 'pull_request'" === $repositoryLifecycle['if']
        && true === $repositoryLifecycle['with']['prepare-release']
        && true === $repositoryLifecycle['with']['publish']
        && 'main' === $repositoryLifecycle['with']['base-branch']
        && '${{ vars.CHANGELOG_AUTOMATION_ACTOR || \'github-actions[bot]\' }}'
            === $repositoryLifecycle['with']['automation-actor'],
    'Trusted events must use the local facade while preserving the existing preparation, publication and Bot configuration.',
);
verifyWorkflow(
    ! isset($repository['concurrency']) && ! isset($repositoryLifecycle['concurrency'])
        && ! isset($lifecycle['concurrency'])
        && 'check-changelog-${{ github.repository }}-${{ github.event.pull_request.number }}'
            === $repositoryCheck['concurrency']['group']
        && true === $repositoryCheck['concurrency']['cancel-in-progress'],
    'Only the read-only check caller may hold a cancellation lock; trusted facade calls must delegate their locks.',
);
$versionLock = Yaml::parseFile($root . '/.github/workflows/changelog-version.yml')['jobs']['changelog']['concurrency'];
$publicationLock = Yaml::parseFile(
    $root . '/.github/workflows/changelog-publish.yml',
)['jobs']['changelog']['concurrency'];
verifyWorkflow(
    $versionLock === $publicationLock
        && 'changelog-${{ inputs.repository || github.repository }}-${{ inputs.base-branch }}' === $versionLock['group']
        && false === $versionLock['cancel-in-progress'],
    'Preparation and publication must serialize only at the shared module lock without cancelling each other.',
);
verifyWorkflow(
    'changelog-dependabot-${{ inputs.repository || github.repository }}-${{ inputs.pull-request }}'
        === Yaml::parseFile(
            $root . '/.github/workflows/changelog-dependabot.yml',
        )['jobs']['changelog']['concurrency']['group']
        && 'changelog-feedback-${{ github.repository }}-${{ inputs.pull-request }}' === $feedback['concurrency']['group']
        && 'docker-cli-${{ github.repository }}-${{ inputs.tag || github.ref_name }}' === $repositoryImage['concurrency']['group'],
    'Fragment, feedback and image operations must keep separate lock namespaces from the approved release transaction.',
);
$assertions += 2;
$image = $repository['jobs']['image'];
verifyWorkflow(
    'changelog' === $image['needs']
        && str_contains($image['if'], "needs.changelog.result == 'success'")
        && str_contains($image['if'], "needs.changelog.outputs.published-version != ''")
        && str_contains($image['if'], "needs.changelog.outputs.published-tag != ''")
        && str_contains($image['if'], "needs.changelog.outputs.published-sha != ''")
        && '${{ needs.changelog.outputs.published-tag }}' === $image['with']['tag']
        && '${{ needs.changelog.outputs.published-sha }}' === $image['with']['expected-sha'],
    'Only effective publication outputs may select the image tag and its approved source SHA.',
);
foreach (['version', 'tag', 'sha'] as $output) {
    verifyWorkflow(
        '${{ jobs.publish.outputs.' . $output . ' }}' === $lifecycle['on']['workflow_call']['outputs']['published-' . $output]['value'],
        'Image selectors must originate in publication rather than version preparation.',
    );
    ++$assertions;
}
foreach ([
    'check-changelog',
    'dependabot-changelog',
    'release-changelog',
    'publish-changelog',
    'tests',
    'docker-cli',
] as $retired) {
    verifyWorkflow(
        ! is_file($root . '/.github/workflows/' . $retired . '.yml'),
        'Previous internal workflows must not compete with the single repository caller: ' . $retired,
    );
    ++$assertions;
}
verifyWorkflow(
    13 === count(glob($root . '/.github/workflows/*.yml')),
    'The seven public contracts and six repository workflows must remain distinct.',
);
$assertions += 6;

verifyWorkflow(
    'Repository: Documentation' === $repositoryDocumentation['name']
        && ['contents' => 'read'] === $repositoryDocumentation['permissions']
        && ['pull_request', 'push', 'workflow_dispatch'] === array_keys($repositoryDocumentation['on'])
        && ['main'] === $repositoryDocumentation['on']['push']['branches']
        && ['impact', 'documentation'] === array_keys($repositoryDocumentation['jobs']),
    'Documentation must build repository sources with read-only permissions and no publication job.',
);
$documentation = $repositoryDocumentation['jobs']['documentation'];
verifyWorkflow(
    'ubuntu-latest' === $documentation['runs-on']
        && ! isset($documentation['permissions'])
        && ! isset($documentation['secrets'])
        && false === workflowStep($documentation, 'Check Out Repository')['with']['persist-credentials']
        && 'composer --no-plugins docs:build' === workflowStep(
            $documentation,
            'Build Guides And PHP API Reference',
        )['run'],
    'The documentation runner must build the checked-out revision without persisted repository credentials.',
);
$documentationArtifact = workflowStep($documentation, 'Upload Documentation Preview');
verifyWorkflow(
    'actions/upload-artifact@v7.0.1' === $documentationArtifact['uses']
        && '.build/docs' === $documentationArtifact['with']['path']
        && 'error' === $documentationArtifact['with']['if-no-files-found']
        && 14 === $documentationArtifact['with']['retention-days']
        && str_contains(
            workflowStep($documentation, 'Require Rendered Guides And API')['run'],
            'test -s .build/docs/namespaces/fastforward-changelog.html',
        ),
    'Documentation must require actual rendered output and upload that site as an artifact.',
);
$assertions += 3;

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
foreach ([$feedback, $lifecycle, $repository] as $workflow) {
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

$dependabot = Yaml::parseFile($root . '/.github/workflows/changelog-dependabot.yml');
$fragment = $dependabot['jobs']['changelog'];
$runtime = workflowStep($fragment, 'Check Out The Called Workflow Runtime');
$operation = workflowStep($fragment, 'Run The Shared Dependabot Action');
$lifecycleFeedback = $lifecycle['jobs']['feedback'];
verifyWorkflow(
    '${{ fromJSON(toJSON(job)).workflow_sha }}' === $runtime['with']['ref']
        && '${{ fromJSON(toJSON(job)).workflow_repository }}' === $runtime['with']['repository']
        && false === $runtime['with']['persist-credentials'],
    'Privileged Dependabot runtime must use the trusted workflow commit, never a stale PR base snapshot.',
);
verifyWorkflow(
    '${{ steps.operation.outputs.head-sha }}' === $fragment['outputs']['head-sha']
        && 'operation' === $operation['id']
        && './.changelog-runtime/.github/actions/dependabot' === $operation['uses'],
    'Dependabot must forward the fragment action head for same-run feedback.',
);
verifyWorkflow(
    'dependabot' === $lifecycleFeedback['needs'] && str_contains($lifecycleFeedback['if'], '!cancelled()'),
    'Feedback must follow fragment creation and preserve failures instead of suppressing diagnostics.',
);
verifyWorkflow(
    '${{ needs.dependabot.outputs.head-sha || github.event.pull_request.head.sha }}'
        === $lifecycleFeedback['with']['head-sha']
        && '${{ github.event.pull_request.number }}' === $lifecycleFeedback['with']['pull-request'],
    'Feedback must validate the new fragment head or the unchanged immutable event head.',
);
verifyWorkflow(
    '${{ needs.dependabot.result != \'success\' && github.event.pull_request.base.sha || \'\' }}'
        === $lifecycleFeedback['with']['base-sha'],
    'Successful Dependabot authoring must resolve the live API base; other feedback retains the event base.',
);
verifyWorkflow(
    'always' === $repositoryLifecycle['with']['comment-mode']
        && '${{ secrets.CHANGELOG_TOKEN || github.token }}' === $repositoryLifecycle['secrets']['token'],
    'Repository feedback must update its sticky comment using the existing trusted token contract.',
);
$assertions += 6;

// This pinned action reads optional lookup flags as strings: even the literal "false" enables a lookup.
foreach ([$dependabot] as $workflow) {
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
    true === $lifecycle['on']['workflow_call']['inputs']['publish']['default'],
    'The normal lifecycle must publish approved merges unless the consumer explicitly disables publication.',
);
verifyWorkflow(
    'always' === $lifecycle['on']['workflow_call']['inputs']['comment-mode']['default'],
    'Feedback must default to one updated comment.',
);
$versionWorkflow = Yaml::parseFile($root . '/.github/workflows/changelog-version.yml');
$versionAction = Yaml::parseFile($root . '/.github/actions/version/action.yml');
verifyWorkflow(
    'chore(release): {repository} v{current_version} → v{next_version}' === $lifecycle['on']['workflow_call']['inputs']['version-pr-title']['default']
        && '${{ inputs.version-pr-title }}' === $lifecycle['jobs']['version']['with']['title']
        && 'chore(release): {repository} v{current_version} → v{next_version}' === $versionWorkflow['on']['workflow_call']['inputs']['title']['default']
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
            if ('publish' === $name) {
                verifyWorkflow(
                    '${{ inputs.publish-app-client-id || inputs.app-client-id }}' === $job['with']['app-client-id']
                        && '${{ inputs.publish-environment }}' === $job['with']['environment']
                        && '${{ secrets.publish-app-private-key || secrets.app-private-key }}'
                            === $job['secrets']['app-private-key']
                        && "\${{ inputs.publish-app-client-id == '' && secrets.token || '' }}"
                            === $job['secrets']['token'],
                    'Publication-only App credentials must not change the other lifecycle operations.',
                );
            } elseif ('check' !== $name && isset($job['uses'])) {
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
    $feedbackConditionGuard = <<<'JS_WRAP'
    const snapshot = JSON.parse(process.env.FF_FEEDBACK_CONTEXT);
    const condition = process.env.FF_FEEDBACK_CONDITION.replace(
      /\bcancelled\(\)/g, snapshot.cancelled ? 'true' : 'false',
    ).replace(
      /\b(?:inputs|needs|steps)(?:\.[A-Za-z_][A-Za-z0-9_-]*)+/g,
      path => JSON.stringify(path.split('.').reduce((value, key) => value?.[key], snapshot) ?? ''),
    );
    core.setOutput('eligible', new Function('return Boolean(' + condition + ')')());
    JS_WRAP;
    foreach (['valid', 'invalid', 'obsolete', ''] as $status) {
        foreach (['always', 'on-failure', 'never'] as $mode) {
            foreach ([false, true] as $cancelled) {
                $evaluated = workflowScriptGuard($feedbackConditionGuard, $directory, [
                    'FF_FEEDBACK_CONTEXT' => json_encode([
                        'cancelled' => $cancelled, 'inputs' => ['comment-mode' => $mode],
                        'needs' => ['status' => ['outputs' => ['status' => $status]]],
                    ], JSON_THROW_ON_ERROR),
                    'FF_FEEDBACK_CONDITION' => $comment['if'],
                ], [], 0);
                verifyWorkflow(
                    ['eligible' => ! $cancelled && 'obsolete' !== $status && 'never' !== $mode] === $evaluated['outputs'],
                    'Obsolete feedback must never enter the comment job; real failures retain their configured feedback.',
                );
                ++$assertions;
            }
        }
    }
    foreach (['success', 'failure', 'skipped', 'cancelled'] as $outcome) {
        $evaluated = workflowScriptGuard($feedbackConditionGuard, $directory, [
            'FF_FEEDBACK_CONTEXT' => json_encode([
                'steps' => ['operation' => ['outcome' => $outcome]]],
                JSON_THROW_ON_ERROR,
            ),
            'FF_FEEDBACK_CONDITION' => $preserveFailure['if'],
        ], [], 0);
        verifyWorkflow(
            ['eligible' => 'success' !== $outcome] === $evaluated['outputs'],
            'An explicit obsolete result exits successfully; all unsuccessful operation outcomes retain the failure gate.',
        );
        ++$assertions;
    }
    $imageGuard = <<<'JS_WRAP'
    const snapshot = JSON.parse(process.env.FF_IMAGE_CONTEXT);
    const condition = process.env.FF_IMAGE_CONDITION.replace(
      /\b(?:github|needs)(?:\.[A-Za-z_][A-Za-z0-9_-]*)+/g,
      path => JSON.stringify(path.split('.').reduce((value, key) => value?.[key], snapshot) ?? ''),
    );
    core.setOutput('eligible', new Function('return Boolean(' + condition + ')')());
    JS_WRAP;
    $approvedImage = str_repeat('a', 40);
    $imageContext = [
        'github' => [
            'event_name' => 'pull_request_target', 'repository' => 'fixture/consumer',
            'event' => [
                'action' => 'closed',
                'pull_request' => [
                    'merged' => true, 'merge_commit_sha' => $approvedImage,
                    'head' => ['ref' => 'changelog/version', 'repo' => ['full_name' => 'fixture/consumer']],
                    'base' => ['ref' => 'main'],
                ],
            ],
        ],
        'needs' => ['changelog' => [
            'result' => 'success',
            'outputs' => [
                'published-version' => '1.2.3', 'published-tag' => 'v1.2.3', 'published-sha' => $approvedImage,
            ],
        ]],
    ];
    foreach ([
        [[], true],
        [['github.event_name' => 'pull_request'], false],
        [['github.event_name' => 'push'], false],
        [['github.event_name' => 'workflow_dispatch'], false],
        [['github.event.action' => 'opened'], false],
        [['github.event.pull_request.merged' => false], false],
        [['github.event.pull_request.head.repo.full_name' => 'fork/consumer'], false],
        [['github.event.pull_request.head.ref' => 'feature/untrusted'], false],
        [['github.event.pull_request.head.ref' => "changelog/version' || true || '"], false],
        [['github.event.pull_request.base.ref' => 'release'], false],
        [['github.event.pull_request.merge_commit_sha' => str_repeat('b', 40)], false],
        [['github.event.pull_request' => null], false],
        [['needs.changelog.result' => 'failure'], false],
        [['needs.changelog.result' => 'skipped'], false],
        [['needs.changelog.outputs.published-version' => ''], false],
        [['needs.changelog.outputs.published-tag' => ''], false],
        [['needs.changelog.outputs.published-sha' => ''], false],
        [['needs.changelog.outputs.published-sha' => str_repeat('b', 40)], false],
    ] as [$overrides, $expected]) {
        $snapshot = $imageContext;
        foreach ($overrides as $path => $value) {
            $segments = explode('.', $path);
            $last = array_pop($segments);
            $cursor = &$snapshot;
            foreach ($segments as $segment) {
                $cursor = &$cursor[$segment];
            }
            $cursor[$last] = $value;
            unset($cursor);
        }
        $evaluated = workflowScriptGuard($imageGuard, $directory, [
            'FF_IMAGE_CONTEXT' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'FF_IMAGE_CONDITION' => $image['if'],
        ], [], 0);
        verifyWorkflow(
            ['eligible' => $expected] === $evaluated['outputs'] && [] === $evaluated['requests'],
            'Complete opaque outputs cannot authorize an image outside the exact approved merged-PR event.',
        );
        ++$assertions;
    }
    $movedBase = str_repeat('e', 40);
    $resolved = workflowScriptGuard(
        $feedback['jobs']['status']['steps'][0]['with']['script'],
        $directory,
        [
            'FF_RUNTIME_REPOSITORY' => 'php-fast-forward/changelog',
            'FF_RUNTIME_SHA' => str_repeat('c', 40),
            'FF_EVENT' => 'pull_request_target', 'FF_REF' => 'refs/heads/main',
            'FF_EVENT_PR' => '7', 'FF_PR' => '7', 'FF_HEAD' => str_repeat('d', 40),
            'FF_BASE' => '', 'FF_BRANCH' => 'main', 'FF_COMMENT_MODE' => 'always',
        ],
        ['state' => 'open', 'head' => ['sha' => str_repeat('d', 40)], 'base' => ['sha' => $movedBase, 'ref' => 'main']],
        0,
    );
    verifyWorkflow(
        ['base' => $movedBase] === $resolved['outputs'] && 1 === count($resolved['requests']),
        'Post-Dependabot feedback must read the moved API base for the newly authored immutable head.',
    );
    ++$assertions;
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
        [['FF_AUTH_CLIENT_ID' => 'Iv23.fixture', 'FF_AUTH_OPERATION' => 'publish',
            'FF_AUTH_EVENT' => 'pull_request_target', 'FF_AUTH_ACTION' => 'closed'], 1, false],
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
        gh() {
          [[ "$1" == 'api' && "$2" == 'repos/fixture/consumer/releases/tags/v0.1.0' ]] || return 71
          [[ "${FF_FIXTURE_RELEASE_FAILURE:-}" != 1 ]] || return 72
          printf '%s\n' "$FF_FIXTURE_RELEASE_RESPONSE"
        }
        if [[ "${FF_FIXTURE_FORBID_DOCKER:-}" == 1 ]]; then
          sudo() { touch unexpected-daemon-operation; return 73; }
          docker() { touch unexpected-daemon-operation; return 73; }
          jq() { touch unexpected-daemon-operation; return 73; }
        fi
        BASH);

    foreach ([
        ["v0.1.0\tfalse\t" . $approvedImage, false, 0],
        ["v0.1.0\ttrue\t" . $approvedImage, false, 1],
        ["v0.2.0\tfalse\t" . $approvedImage, false, 1],
        ["v0.1.0\tfalse\t" . str_repeat('b', 40), false, 1],
        ["v0.1.0\tfalse\tmain", false, 1],
        ["v0.1.0\tfalse\t" . $approvedImage . "\tunexpected", false, 1],
        ['', false, 1],
        ['', true, 72],
    ] as [$response, $failure, $expected]) {
        workflowGuard($imageReleaseGuard['run'], $directory, [
            'GITHUB_REPOSITORY' => 'fixture/consumer', 'FF_TAG' => 'v0.1.0', 'FF_EXPECTED_SHA' => $approvedImage,
            'FF_FIXTURE_RELEASE_RESPONSE' => $response, 'FF_FIXTURE_RELEASE_FAILURE' => $failure ? '1' : '0',
        ], $expected);
        ++$assertions;
    }

    // GitHub's default Bash runner stops at the first failing output check.
    $documentationGuard = "set -e\n" . workflowStep($documentation, 'Require Rendered Guides And API')['run'];
    workflowGuard($documentationGuard, $directory, [], 1);

    foreach (['index.html', 'adoption.html'] as $page) {
        $filesystem->dumpFile($directory . '/.build/docs/' . $page, '<html>Fixture guide.</html>');
        workflowGuard($documentationGuard, $directory, [], 1);
    }

    $apiPage = $directory . '/.build/docs/namespaces/fastforward-changelog.html';
    $filesystem->dumpFile($apiPage, '');
    workflowGuard($documentationGuard, $directory, [], 1);
    $filesystem->dumpFile($apiPage, '<html>Fixture namespace reference.</html>');
    workflowGuard($documentationGuard, $directory, [], 0);
    $assertions += 5;

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

$channels = Yaml::parseFile($root . '/.github/workflows/repository-release-channels.yml');
$channelJob = $channels['jobs']['channel'];
verifyWorkflow(
    ['release' => ['types' => ['published', 'edited']]] === $channels['on']
        && ! isset($repository['jobs']['channels'])
        && 'release-channels' === $channelJob['environment']
        && ['contents' => 'read'] === $channelJob['permissions']
        && 'release-channels-${{ github.repository }}' === $channels['concurrency']['group']
        && false === $channels['concurrency']['cancel-in-progress']
        && str_contains($channelJob['if'], "github.repository == 'php-fast-forward/changelog'")
        && str_contains($channelJob['if'], 'github.event.release.draft == false')
        && str_contains($channelJob['if'], 'github.event.release.prerelease == false'),
    'Channels require a real published stable release event, a trusted repository and a non-cancelling lock.',
);
++$assertions;
verifyWorkflow(
    'Actions-R-Us/actions-tagger@v2'
        === workflowStep($channelJob, 'Update The Released Major Branch')['uses']
        && [
            'token' => '${{ steps.channel-app.outputs.token }}',
            'prefer_branch_releases' => 'true', 'publish_latest_tag' => '${{ steps.release.outputs.latest }}',
        ] === workflowStep($channelJob, 'Update The Released Major Branch')['with']
        && [
            'client-id' => '${{ vars.RELEASE_CHANNEL_APP_CLIENT_ID }}',
            'private-key' => '${{ secrets.app-private-key }}',
            'owner' => 'php-fast-forward', 'repositories' => 'changelog',
            'permission-contents' => 'write', 'permission-workflows' => 'write',
            'skip-token-revoke' => 'false',
        ] === workflowStep($channelJob, 'Create The Channel App Token')['with']
        && 3 === count($channelJob['steps'])
        && "steps.release.outputs.promote == 'true'" === workflowStep($channelJob, 'Create The Channel App Token')['if']
        && "steps.release.outputs.promote == 'true'" === workflowStep(
            $channelJob,
            'Update The Released Major Branch',
        )['if'],
    'The requested Marketplace branch-mode candidate uses the repository-scoped App without restoring a custom updater.',
);
++$assertions;

$channelDirectory = sys_get_temp_dir() . '/changelog-channel-selection-' . bin2hex(random_bytes(6));
$filesystem->mkdir($channelDirectory);
try {
    $selection = workflowStep($channelJob, 'Select The Published Release Channels');
    verifyWorkflow(
        'actions/github-script@v9.0.0' === $selection['uses'],
        'Release selection uses the existing SDK action.',
    );
    $tags = array_map(static fn(string $name): array => ['name' => $name],
        ['v0.4.1', 'v0.4.2', 'v1.0.0', 'v1.1.0', 'latest']);
    foreach ([
        ['v0.4.2', array_slice($tags, 0, 2), ['promote' => true, 'latest' => true], 0],
        ['v0.4.1', $tags, ['promote' => false, 'latest' => false], 0],
        ['v1.0.0', array_slice($tags, 0, 3), ['promote' => true, 'latest' => true], 0],
        ['v1.0.0', $tags, ['promote' => false, 'latest' => false], 0],
        ['v0.4.3', [...$tags, ['name' => 'v0.4.3']], ['promote' => true, 'latest' => false], 0],
        ['v0', $tags, [], 1], ['v1.0.0-rc1', $tags, [], 1], ["v0.4.2\n", $tags, [], 1],
    ] as [$tag, $catalog, $outputs, $status]) {
        $result = workflowScriptGuard($selection['with']['script'], $channelDirectory, [
            'FF_RELEASE_TAG' => $tag,
        ], ['tags' => $catalog], $status);
        verifyWorkflow(
            $outputs === $result['outputs'],
            'Release selection must suppress stale majors and cross-major latest regression.',
        );
        ++$assertions;
    }
    workflowScriptGuard($selection['with']['script'], $channelDirectory, [
        'FF_RELEASE_TAG' => 'v0.4.2', 'FF_FIXTURE_API_FAILURE' => '1',
    ], [], 1);
    ++$assertions;
} finally {
    $filesystem->remove($channelDirectory);
}

$publicationWorkflow = Yaml::parseFile($root . '/.github/workflows/changelog-publish.yml');
$publicationJob = $publicationWorkflow['jobs']['changelog'];
verifyWorkflow(
    '' === $lifecycle['on']['workflow_call']['inputs']['publish-app-client-id']['default']
        && '' === $lifecycle['on']['workflow_call']['inputs']['publish-environment']['default']
        && '' === $publicationWorkflow['on']['workflow_call']['inputs']['environment']['default']
        && '${{ inputs.environment }}' === $publicationJob['environment']
        && ! isset(workflowStep($publicationJob, 'Authenticate Trusted GitHub Operations')['with']['automation-actor'])
        && '${{ inputs.automation-actor }}'
            === workflowStep($publicationJob, 'Run The Shared Publish Action')['with']['automation-actor']
        && 'Iv23liHqRng02H747IYa' === $repositoryLifecycle['with']['publish-app-client-id']
        && 'release-channels' === $repositoryLifecycle['with']['publish-environment'],
    'A separate publisher App must preserve the original managed-PR Bot verification and default consumer behavior.',
);
++$assertions;

$report = $lifecycle['jobs']['report'];
verifyWorkflow(
    ['version', 'publish'] === $report['needs']
        && str_contains($report['if'], '!cancelled()')
        && [] === $report['permissions']
        && '${{ fromJSON(inputs.runs-on) }}' === $report['runs-on'],
    'The lifecycle report must observe both stages even after failure or skips without requesting API permissions.',
);
++$assertions;
$summaryStep = workflowStep($report, 'Summarize The Lifecycle Without Changing Its Outcome');
verifyWorkflow(
    '${{ inputs.prepare-release }}' === $summaryStep['env']['FF_PREPARATION_ENABLED'],
    'The visible summary must receive the configured preparation setting.',
);
++$assertions;
$summaryDirectory = sys_get_temp_dir() . '/changelog-summary-' . bin2hex(random_bytes(6));
$filesystem->mkdir($summaryDirectory);
try {
    foreach ([
        ['success', 'skipped', 'true', 'true', 'false', 'created', '', 'Automatic publication is enabled.', ''],
        ['success', 'skipped', 'true', 'false', 'false', 'created', '', 'Publication is disabled', '::warning::'],
        ['skipped', 'success', 'true', 'true', 'false', '', 'published', 'Publication result: `published`.', ''],
        ['skipped', 'failure', 'true', 'true', 'false', '', '', 'Action required', '::error::'],
        ['failure', 'skipped', 'true', 'true', 'false', '', '', 'Action required', '::error::'],
        ['success', 'skipped', 'true', 'true', 'true', 'dry-run', '', 'Preview only', ''],
        ['success', 'skipped', 'true', 'false', 'true', 'dry-run', '', 'Preview only', ''],
        ['skipped', 'skipped', 'false', 'true', 'false', '', '', 'Version preparation is disabled', ''],
        ['skipped', 'skipped', 'false', 'false', 'false', '', '', 'Publication is also disabled', ''],
        ['skipped', 'success', 'false', 'true', 'false', '', 'published', 'Publication remains enabled', ''],
        ['skipped', 'skipped', 'false', 'false', 'true', '', '', 'Preview only', ''],
    ] as $index => [$preparation, $publication, $prepareEnabled, $enabled, $dryRun, $versionStatus, $publicationState, $expectedText, $annotation]) {
        $summaryFile = $summaryDirectory . '/summary-' . $index;
        $process = new Process(['bash', '-c', $summaryStep['run']], $summaryDirectory, [
            'GITHUB_STEP_SUMMARY' => $summaryFile, 'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'BASH_ENV' => false,
            'FF_PREPARATION' => $preparation, 'FF_PUBLICATION' => $publication,
            'FF_PREPARATION_ENABLED' => $prepareEnabled,
            'FF_VERSION_STATUS' => $versionStatus, 'FF_PUBLICATION_STATE' => $publicationState,
            'FF_PUBLICATION_ENABLED' => $enabled, 'FF_DRY_RUN' => $dryRun,
            'FF_PULL_REQUEST' => 'false' === $prepareEnabled ? '' : '7',
            'FF_VERSION' => 'false' === $prepareEnabled ? '' : '1.7.2',
            'FF_TAG' => 'v1.7.2', 'FF_SHA' => str_repeat('a', 40),
        ]);
        $process->setTimeout(10);
        $process->mustRun();
        $summary = file_get_contents($summaryFile);
        verifyWorkflow(
            str_contains($summary, $expectedText),
            'The visible summary lost its explicit lifecycle explanation.',
        );
        verifyWorkflow(
            str_contains($summary, '| Prepare version PR | ' . $preparation),
            'Preparation and publication must be reported separately.',
        );
        verifyWorkflow(
            '' === $annotation || str_contains($process->getOutput(), $annotation),
            'Explicitly disabled or failed publication needs a visible annotation.',
        );
        verifyWorkflow(
            'true' !== $dryRun || ! str_contains($process->getOutput(), '::warning::'),
            'Preview mode must not claim publication was missed.',
        );
        verifyWorkflow(
            'false' !== $prepareEnabled || (! str_contains($summary, 'Preparation creates a reviewable PR')
                && ! str_contains($summary, 'Merging a version PR updates')
                && ! str_contains($summary, 'Reported version PR:')
                && ! str_contains($process->getOutput(), '::warning::')),
            'Disabled preparation must not imply a version PR was created or warn about its missing publication.',
        );
        $assertions += 5;
    }
    foreach ([['FF_PREPARATION' => 'unrecognized'], ['FF_PUBLICATION_ENABLED' => 'unrecognized'],
        ['FF_PREPARATION_ENABLED' => 'unrecognized']] as $index => $override) {
        $invalidSummary = $summaryDirectory . '/invalid-' . $index;
        $process = new Process(['bash', '-c', $summaryStep['run']], $summaryDirectory, [
            'GITHUB_STEP_SUMMARY' => $invalidSummary, 'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'BASH_ENV' => false,
            'FF_PREPARATION' => 'success', 'FF_PUBLICATION' => 'skipped', 'FF_PUBLICATION_ENABLED' => 'true',
            'FF_PREPARATION_ENABLED' => 'true',
            'FF_DRY_RUN' => 'false', 'FF_VERSION_STATUS' => '', 'FF_PUBLICATION_STATE' => '',
            'FF_PULL_REQUEST' => '', 'FF_VERSION' => '', 'FF_TAG' => '', 'FF_SHA' => '', ...$override,
        ]);
        $process->setTimeout(10);
        verifyWorkflow(
            1 === $process->run() && ! is_file($invalidSummary),
            'Invalid context must not render an invented lifecycle outcome.',
        );
        ++$assertions;
    }
} finally {
    $filesystem->remove($summaryDirectory);
}

$impact = Yaml::parseFile($root . '/.github/workflows/repository-impact.yml');
$impactGuard = workflowStep($impact['jobs']['impact'], 'Select Validation From Immutable PR Changes');
verifyWorkflow(
    ['contents' => 'read'] === $impact['permissions']
        && '${{ github.event.pull_request.base.sha || github.sha }}' === $impactGuard['env']['FF_CLASSIFIER_SHA']
        && str_contains($impactGuard['run'], 'git --no-replace-objects cat-file blob')
        && 0 === workflowStep($impact['jobs']['impact'], 'Check Out The Comparison History')['with']['fetch-depth']
        && false === workflowStep(
            $impact['jobs']['impact'],
            'Check Out The Comparison History',
        )['with']['persist-credentials'],
    'Validation scope must use complete read-only history and retain no checkout credential.',
);
foreach ([$repositoryCi, $repositoryDocumentation] as $workflow) {
    verifyWorkflow(
        './.github/workflows/repository-impact.yml' === $workflow['jobs']['impact']['uses']
            && ! isset($workflow['on']['pull_request']['paths'])
            && ! isset($workflow['on']['pull_request']['paths-ignore']),
        'Required workflow contexts must remain present on editorial and release PRs.',
    );
}
foreach (['packaging', 'component-compatibility', 'tests'] as $jobName) {
    $job = $repositoryCi['jobs'][$jobName];
    verifyWorkflow(
        'impact' === $job['needs'] && '${{ always() && !cancelled() }}' === $job['if']
            && str_contains($job['steps'][0]['run'], 'exit 1')
            && '${{ needs.impact.result }}' === $job['steps'][0]['env']['FF_RESULT'],
        'A failed or missing impact decision must fail each required matrix context.',
    );
    foreach (array_slice($job['steps'], 2) as $step) {
        verifyWorkflow(
            str_contains($step['if'], "needs.impact.outputs.runtime == 'true'"),
            'Every expensive matrix step must require the runtime scope: ' . $step['name'],
        );
        ++$assertions;
    }
}
foreach ([['success', 'true', 0], ['success', 'false', 0], ['failure', 'false', 1],
    ['skipped', 'false', 1], ['success', '', 1], ['success', 'anything', 1]] as [$result, $runtime, $expected]) {
    $decision = new Process(['bash', '-c', $repositoryCi['jobs']['tests']['steps'][0]['run']], $root, [
        'FF_RESULT' => $result, 'FF_RUNTIME' => $runtime, 'BASH_ENV' => false,
        'GITHUB_TOKEN' => false, 'GH_TOKEN' => false,
    ]);
    $decision->setTimeout(10);
    verifyWorkflow($expected === $decision->run(), 'The actual required-check gate must reject incomplete scope.');
    ++$assertions;
}
verifyWorkflow(
    str_contains($repositoryCi['jobs']['coverage-report']['if'], "needs.impact.outputs.runtime == 'true'")
        && str_contains(
            workflowStep($repositoryCi['jobs']['tests'], 'Run The Package Quality And Unit Coverage Gates')['if'],
            "matrix.os == 'ubuntu-latest'",
        )
        && str_contains(
            workflowStep($repositoryCi['jobs']['tests'], 'Test Unit Contracts On This Platform')['if'],
            "matrix.os != 'ubuntu-latest'",
        ),
    'One Linux job owns quality and 100% coverage; every other platform still executes unit contracts.',
);
$documentationJob = $repositoryDocumentation['jobs']['documentation'];
verifyWorkflow(
    'impact' === $documentationJob['needs']
        && '${{ always() && !cancelled() }}' === $documentationJob['if']
        && str_contains($documentationJob['steps'][0]['run'], 'exit 1'),
    'Documentation must also reject failed or incomplete scope decisions.',
);
foreach (array_slice($documentationJob['steps'], 2) as $step) {
    verifyWorkflow(
        "needs.impact.outputs.documentation == 'true'" === $step['if'],
        'Every documentation build step must require changed documentation inputs.',
    );
    ++$assertions;
}
$impactFixtures = new Process(['node', '--test', $root . '/tests/Workflow/ci-impact.test.mjs'], $root, [
    'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'GH_HOST' => false,
    'FF_IMPACT_GUARD' => $impactGuard['run'],
]);
$impactFixtures->setTimeout(30);
$impactFixtures->mustRun();
echo $impactFixtures->getOutput();
$assertions += 9;

echo json_encode(['status' => 'passed', 'workflow_cases' => $assertions, 'network_requests' => 0,
    'publication' => false, 'consumer_installation' => false], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
