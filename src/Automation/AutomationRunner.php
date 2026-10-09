<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Automation;

use FastForward\Changelog\Automation\Dependabot\DependabotFragmentServiceInterface;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactoryInterface;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestServiceInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseApplierInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Validation\CheckServiceInterface;

/** Keeps transport orchestration thin: fragment, version, history and publication rules stay in services. */
final readonly class AutomationRunner implements AutomationRunnerInterface
{
    private const array SETTINGS = [
        'working-directory' => 'workingDirectory', 'fragment-directory' => 'fragmentDirectory',
        'changelog-file' => 'changelogFile', 'locale' => 'locale', 'template' => 'template',
        'base-ref' => 'baseRef', 'tag-prefix' => 'tagPrefix', 'repository' => 'repository', 'source' => 'source',
    ];

    private const array OPERATION_INPUTS = [
        'check' => ['since', 'pull-request', 'managed-branch', 'automation-actor', 'waiver-label', 'maintenance-label'],
        'dependabot' => [
            'pull-request',
            'expected-head-sha',
            'dependency-names',
            'dependency-type',
            'ecosystem',
            'security-alert-numbers',
            'include-dev',
            'include-actions',
        ],
        'version' => ['base-branch', 'managed-branch', 'automation-actor', 'title', 'dry-run'],
        'publish' => [
            'target-sha',
            'pull-request',
            'expected-head-sha',
            'base-branch',
            'managed-branch',
            'automation-actor',
            'dry-run',
        ],
        'history' => ['operation', 'dry-run', 'check'],
    ];

    /** Receives substitutable domain and authority boundaries; no environment or I/O is observed here. */
    public function __construct(
        private ReleaseOptionsFactoryInterface $options,
        private GitRepositoryInterface $git,
        private PullRequestPolicyInterface $policy,
        private CheckServiceInterface $checks,
        private DependabotInputFactoryInterface $dependabotInputs,
        private DependabotFragmentServiceInterface $dependabot,
        private VersionPullRequestInputFactoryInterface $versionInputs,
        private VersionPullRequestServiceInterface $versions,
        private PublicationServiceInterface $publication,
        private ReleasePlannerInterface $planner,
        private ReleaseApplierInterface $applier,
        private GitHubClientInterface $github,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /** Validates the complete explicit input surface before selecting one package operation. */
    public function run(string $operation, array $inputs): array
    {
        if (! isset(self::OPERATION_INPUTS[$operation])) {
            throw $this->exceptions->invalid('Unknown automation operation.');
        }
        $unknown = array_diff(
            array_keys($inputs),
            [...array_keys(self::SETTINGS),
                ...self::OPERATION_INPUTS[$operation],
            ]);
        if ([] !== $unknown) {
            throw $this->exceptions->invalid('Unknown Action inputs: ' . implode(', ', $unknown));
        }
        $settings = [];
        foreach (self::SETTINGS as $input => $property) {
            if (array_key_exists($input, $inputs)) {
                $settings[$property] = $this->text($inputs, $input);
            }
        }
        $options = $this->options->create($settings);
        if (in_array($operation, ['dependabot', 'version'], true)
            || (in_array($operation, ['check', 'publish'], true) && '' !== $this->text($inputs, 'pull-request'))) {
            $root = $this->git->repositoryRoot($options->workingDirectory);
            if (rtrim(str_replace('\\', '/', $options->workingDirectory), '/') !== $root) {
                throw $this->exceptions->invalid(
                    'GitHub automation requires the repository root as working-directory; select nested projects using repository-relative fragment-directory/changelog-file/template inputs.',
                );
            }
        }

        return match ($operation) {
            'check' => $this->check($options, $inputs),
            'dependabot' => $this->dependabot($options, $inputs),
            'version' => $this->version($options, $inputs),
            'publish' => $this->publish($options, $inputs),
            'history' => $this->history($options, $inputs),
        };
    }

    /** Binds live PR permissions to this exact checkout before contribution validation. */
    private function check(ReleaseOptions $options, array $inputs): array
    {
        $since = $this->text($inputs, 'since');
        if ('' === $since) {
            throw $this->exceptions->invalid('The check Action requires since to establish contribution evidence.');
        }
        $central = false;
        $waiver = false;
        $diagnostics = [];
        $kind = 'ordinary';
        if ('' !== $this->text($inputs, 'pull-request')) {
            $authorization = $this->policy->inspect(
                $options,
                $this->number($inputs, 'pull-request'),
                $this->text($inputs, 'managed-branch', 'changelog/version'),
                $this->text($inputs, 'automation-actor', 'github-actions[bot]'),
                $this->text($inputs, 'waiver-label', 'changelog-not-required'),
                $this->text($inputs, 'maintenance-label', 'changelog-maintenance'),
            );
            if (null === $authorization->headSha || $authorization->headSha !== $this->git->resolveRef(
                $options->workingDirectory,
            )) {
                throw $this->exceptions->failure('PR authorization is unavailable or belongs to a different checkout.');
            }
            $central = $authorization->centralChangeAuthorized;
            $waiver = $authorization->waiverAuthorized;
            $diagnostics = $authorization->diagnostics;
            $kind = $authorization->kind;
        }
        $report = $this->checks->check($options, $since, $central, $waiver, $kind);
        if (! $report->isValid()) {
            throw $this->exceptions->failure(
                'Changelog check failed: ' . json_encode(
                    $report->errors,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            );
        }

        return ['status' => 'valid', 'fragments' => count(
            $report->changesets,
        ), 'waived' => $report->waived, 'kind' => $kind, 'diagnostics' => $diagnostics];
    }

    /** Passes only validated, caller-supplied Dependabot metadata to the create-only service. */
    private function dependabot(ReleaseOptions $options, array $inputs): array
    {
        $alerts = json_decode($this->text($inputs, 'security-alert-numbers', '[]'), false, 512, JSON_THROW_ON_ERROR);
        if (! is_array($alerts) || ! array_is_list($alerts)) {
            throw $this->exceptions->invalid('security-alert-numbers must be a JSON array of alert numbers.');
        }
        $input = $this->dependabotInputs->create(
            $this->number($inputs, 'pull-request'),
            $this->text($inputs, 'expected-head-sha'),
            array_map(trim(...), explode(',', $this->text($inputs, 'dependency-names'))),
            $this->text($inputs, 'dependency-type'),
            $this->text($inputs, 'ecosystem'),
            $alerts,
            $this->boolean($inputs, 'include-dev', true),
            $this->boolean($inputs, 'include-actions', true),
        );
        $result = $this->dependabot->synchronize($options, $input);
        $this->assertStatus($result->status, $result->diagnostics);

        return ['status' => $result->status, 'path' => $result->path, 'head_sha' => $result->commitSha, 'diagnostics' => $result->diagnostics];
    }

    /** Maintains the same release line; Git and GitHub freshness/race rules belong to the shared service. */
    private function version(ReleaseOptions $options, array $inputs): array
    {
        $input = $this->versionInputs->create(
            $this->text($inputs, 'base-branch', 'main'),
            $this->text($inputs, 'managed-branch', 'changelog/version'),
            $this->text($inputs, 'automation-actor', 'github-actions[bot]'),
            $this->text($inputs, 'title', 'chore: update changelog'),
            $this->boolean($inputs, 'dry-run'),
        );
        $result = $this->versions->synchronize($options, $input);
        $this->assertStatus($result->status, $result->diagnostics);

        return ['status' => $result->status, 'pull_request' => $result->prNumber, 'url' => $result->url,
            'head_sha' => $result->headSha, 'plan_id' => $result->planId, 'version' => $result->version,
            'maintenance' => $result->maintenance, 'diagnostics' => $result->diagnostics];
    }

    /** Requires immutable merge and signed-head proof when called as a post-PR publisher. */
    private function publish(ReleaseOptions $options, array $inputs): array
    {
        $target = $this->text($inputs, 'target-sha');
        if (! GitHubEvidence::sha($target)) {
            throw $this->exceptions->invalid('The publish Action requires a complete approved target-sha.');
        }
        if ('' !== $this->text($inputs, 'pull-request')) {
            $number = $this->number($inputs, 'pull-request');
            $head = $this->text($inputs, 'expected-head-sha');
            $actor = $this->text($inputs, 'automation-actor', 'github-actions[bot]');
            $pr = $this->github->request('GET', '/repos/' . $options->repository . '/pulls/' . $number);
            if (! GitHubEvidence::repository($options->repository) || ! GitHubEvidence::sha($head)
                || null === $pr || ($pr['number'] ?? null) !== $number
                || ($pr['state'] ?? null) !== 'closed' || true !== ($pr['merged'] ?? null)
                || ($pr['merge_commit_sha'] ?? null) !== $target || ($pr['head']['sha'] ?? null) !== $head
                || ($pr['head']['repo']['full_name'] ?? null) !== $options->repository
                || ($pr['base']['repo']['full_name'] ?? null) !== $options->repository
                || ($pr['head']['ref'] ?? null) !== $this->text($inputs, 'managed-branch', 'changelog/version')
                || ($pr['base']['ref'] ?? null) !== $this->text($inputs, 'base-branch', 'main')
                || ! GitHubEvidence::identity($pr['user'] ?? null, $actor, 'Bot')
            ) {
                throw $this->exceptions->failure(
                    'Publication requires the merged configured version PR and its immutable head/merge identities.',
                );
            }
            $account = $this->github->request('GET', '/users/' . rawurlencode($actor));
            if (! GitHubEvidence::identity($account, $actor, 'Bot', $pr['user']['id'])
                || ! $this->policy->inspectHead($options, $head, $actor, $target)) {
                throw $this->exceptions->failure('The merged version PR lacks signed automation ownership.');
            }
        } elseif ('' !== $this->text($inputs, 'expected-head-sha')) {
            throw $this->exceptions->invalid('expected-head-sha requires pull-request.');
        }

        return $this->publication->publish($options, $target, $this->boolean($inputs, 'dry-run'))->summary();
    }

    /** Keeps historical maintenance local, with preview/check behavior matching the CLI. */
    private function history(ReleaseOptions $options, array $inputs): array
    {
        $operation = $this->text($inputs, 'operation', 'backfill');
        $dryRun = $this->boolean($inputs, 'dry-run');
        $check = $this->boolean($inputs, 'check');
        if (! in_array($operation, ['backfill', 'format'], true) || ($dryRun && $check)) {
            throw $this->exceptions->invalid(
                'History requires backfill or format and mutually exclusive dry-run/check.',
            );
        }
        $plan = $this->planner->plan($options, $operation);
        if ($check && ! $this->applier->isApplied($plan)) {
            throw $this->exceptions->failure('Historical maintenance differs from the expected applied plan.');
        }
        $applied = ! $dryRun && ! $check && $this->applier->apply($plan);

        return [...$plan->summary(), 'status' => $dryRun ? 'dry-run' : ($check ? 'verified' : ($applied ? 'applied' : 'unchanged'))];
    }

    /** Rejects ambiguous transport types instead of coercing booleans, arrays or numeric metadata. */
    private function text(array $inputs, string $key, string $default = ''): string
    {
        $value = array_key_exists($key, $inputs) ? $inputs[$key] : $default;
        if (! is_string($value) || str_contains($value, "\0")) {
            throw $this->exceptions->invalid('Action input ' . $key . ' must be a string without NUL.');
        }

        return $value;
    }

    /** Accepts only explicit booleans from the CLI, Docker Actions and workflow_call. */
    private function boolean(array $inputs, string $key, bool $default = false): bool
    {
        $value = array_key_exists($key, $inputs) ? $inputs[$key] : $default;
        if (! in_array($value, [true, false, 'true', 'false'], true)) {
            throw $this->exceptions->invalid('Action input ' . $key . ' must be true or false.');
        }

        return true === $value || 'true' === $value;
    }

    /** Positive PR numbers use canonical decimal syntax and the host's safe integer range. */
    private function number(array $inputs, string $key): int
    {
        $raw = $this->text($inputs, $key);
        $number = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (1 !== preg_match('/\A[1-9][0-9]*\z/', $raw) || false === $number) {
            throw $this->exceptions->invalid('Action input ' . $key . ' must be a positive integer.');
        }

        return $number;
    }

    /** A refused or raced write cannot be reported as a successful workflow. */
    private function assertStatus(string $status, array $diagnostics): void
    {
        if (in_array($status, ['refused', 'conflict'], true)) {
            throw $this->exceptions->failure('Automation ' . $status . ': ' . implode(' ', $diagnostics));
        }
    }
}
