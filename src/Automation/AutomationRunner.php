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
use FastForward\Changelog\Automation\PullRequest\PullRequestStatusServiceInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestServiceInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Publication\MergedPublicationServiceInterface;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseApplierInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Validation\CheckServiceInterface;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** Keeps transport orchestration thin: fragment, version, history and publication rules stay in services. */
final readonly class AutomationRunner implements AutomationRunnerInterface
{
    private const array SETTINGS = [
        'working-directory' => 'workingDirectory', 'fragment-directory' => 'fragmentDirectory',
        'changelog-file' => 'changelogFile', 'locale' => 'locale', 'template' => 'template',
        'base-ref' => 'baseRef', 'tag-prefix' => 'tagPrefix', 'repository' => 'repository', 'source' => 'source',
        'resolve-pull-requests' => 'resolvePullRequests',
    ];

    private const array OPERATION_INPUTS = [
        'pr-status' => [
            'since',
            'pull-request',
            'expected-head-sha',
            'base-branch',
            'managed-branch',
            'automation-actor',
            'waiver-label',
            'maintenance-label',
            'publish-status',
        ],
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

    /**
     * Receives substitutable domain and authority boundaries; no environment or I/O is observed here.
     *
     * The unused GitHub argument retains constructor compatibility; publication owns its API authority.
     *
     * @param ReleaseOptionsFactoryInterface          $options          Validated consumer-settings construction without implicit
     *                                                                  transport input coercion.
     * @param GitRepositoryInterface                  $git              Replaceable repository boundary for immutable blobs, identities, ancestry and
     *                                                                  local Git operations.
     * @param PullRequestPolicyInterface              $policy           Independent verifier for privileged human grants and signed
     *                                                                  generated-commit ownership.
     * @param CheckServiceInterface                   $checks           Contribution validator that preserves inherited fragments and enforces
     *                                                                  verified authorization.
     * @param DependabotInputFactoryInterface         $dependabotInputs Constructor for validated deterministic dependency
     *                                                                  metadata with deduplicated sorted packages and alerts.
     * @param DependabotFragmentServiceInterface      $dependabot       Create-only dependency-fragment operation with live head
     *                                                                  and identity checks.
     * @param VersionPullRequestInputFactoryInterface $versionInputs    Constructor for validated immutable branch, Bot,
     *                                                                  title and preview settings.
     * @param VersionPullRequestServiceInterface      $versions         Managed version-PR synchronizer that owns freshness and
     *                                                                  remote write reconciliation.
     * @param PublicationServiceInterface             $publication      Publication boundary that requires complete approved-commit
     *                                                                  transaction proof.
     * @param ReleasePlannerInterface                 $planner          Planner for deterministic release or historical-maintenance transactions.
     * @param ReleaseApplierInterface                 $applier          Transactional applier and applied-state verifier for planned local
     *                                                                  changes.
     * @param GitHubClientInterface                   $github           Compatibility-only API argument; publication services own API authorization
     *                                                                  and this runner does not use it.
     * @param PullRequestStatusServiceInterface       $pullRequests     Exact-head PR validator and fixed commit-status writer.
     */
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
        GitHubClientInterface $github,
        private PullRequestStatusServiceInterface $pullRequests,
    ) {}

    /**
     * Validates the complete explicit input surface before selecting one package operation.
     *
     * @param  string                   $operation Supported package operation to execute with only its declared input surface.
     * @param  array<string,mixed>      $inputs    Explicit Action input map; values are validated without reading process
     *                                             environment.
     * @return array                    Stable operation-specific output map after complete explicit-input validation.
     * @throws InvalidArgumentException When explicit operation inputs or selected consumer settings are invalid.
     * @throws RuntimeException         When required authority, transaction evidence or a successful operation outcome cannot
     *                                  be established.
     * @throws JsonException            When explicit JSON metadata or machine-readable validation output cannot be encoded or
     *                                  decoded.
     */
    public function run(string $operation, array $inputs): array
    {
        if (! isset(self::OPERATION_INPUTS[$operation])) {
            throw new InvalidArgumentException('Unknown automation operation.');
        }
        $unknown = array_diff(
            array_keys($inputs),
            [...array_keys(self::SETTINGS),
                ...self::OPERATION_INPUTS[$operation],
            ]);

        if ([] !== $unknown) {
            throw new InvalidArgumentException('Unknown Action inputs: ' . implode(', ', $unknown));
        }
        $settings = [];

        foreach (self::SETTINGS as $input => $property) {
            if (array_key_exists($input, $inputs)) {
                $settings[$property] = 'resolvePullRequests' === $property
                    ? $this->boolean($inputs, $input) : $this->text($inputs, $input);
            }
        }
        $options = $this->options->create($settings);

        if (in_array($operation, ['dependabot', 'version'], true)
            || (in_array(
                $operation,
                ['check', 'publish'],
                true,
            ) && '' !== $this->text(
                $inputs,
                'pull-request',
            ))) {
            $root = $this->git->repositoryRoot($options->workingDirectory);

            if (rtrim(str_replace('\\', '/', $options->workingDirectory), '/') !== $root) {
                throw new InvalidArgumentException(
                    'GitHub automation requires the repository root as working-directory; select nested projects using repository-relative fragment-directory/changelog-file/template inputs.',
                );
            }
        }

        return match ($operation) {
            'pr-status' => $this->pullRequests->inspect(
                $options, $this->number($inputs, 'pull-request'),
                $this->text($inputs, 'expected-head-sha'), $this->text($inputs, 'since'),
                $this->text($inputs, 'base-branch', 'main'),
                $this->text($inputs, 'managed-branch', 'changelog/version'),
                $this->text($inputs, 'automation-actor', 'github-actions[bot]'),
                $this->text($inputs, 'waiver-label', 'changelog-not-required'),
                $this->text($inputs, 'maintenance-label', 'changelog-maintenance'),
                $this->boolean($inputs, 'publish-status'),
            ),
            'check' => $this->check($options, $inputs),
            'dependabot' => $this->dependabot($options, $inputs),
            'version' => $this->version($options, $inputs),
            'publish' => $this->publish($options, $inputs),
            'history' => $this->history($options, $inputs),
        };
    }

    /**
     * Binds live PR permissions to this exact checkout before contribution validation.
     *
     * @param  ReleaseOptions           $options Trusted consumer paths, rendering settings and repository selection for this
     *                                           operation.
     * @param  array<string,mixed>      $inputs  Explicit Action input map; values are validated without reading process
     *                                           environment.
     * @return array                    Contribution result with complete valid-inventory count and verified policy diagnostics.
     * @throws InvalidArgumentException When explicit operation inputs or selected consumer settings are invalid.
     * @throws RuntimeException         When required authority, transaction evidence or a successful operation outcome cannot
     *                                  be established.
     * @throws JsonException            When explicit JSON metadata or machine-readable validation output cannot be encoded or
     *                                  decoded.
     */
    private function check(ReleaseOptions $options, array $inputs): array
    {
        $since = $this->text($inputs, 'since');

        if ('' === $since) {
            throw new InvalidArgumentException('The check Action requires since to establish contribution evidence.');
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
                throw new RuntimeException('PR authorization is unavailable or belongs to a different checkout.');
            }
            $central = $authorization->centralChangeAuthorized;
            $waiver = $authorization->waiverAuthorized;
            $diagnostics = $authorization->diagnostics;
            $kind = $authorization->kind;
        }
        $report = $this->checks->check($options, $since, $central, $waiver, $kind);

        if (! $report->isValid()) {
            throw new RuntimeException('Changelog check failed: ' . json_encode(
                $report->errors,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        }

        return ['status' => 'valid', 'fragments' => count(
            $report->changesets,
        ), 'waived' => $report->waived, 'kind' => $kind, 'diagnostics' => $diagnostics];
    }

    /**
     * Passes only validated, caller-supplied Dependabot metadata to the create-only service.
     *
     * @param  ReleaseOptions      $options Trusted consumer paths, rendering settings and repository selection for this
     *                                      operation.
     * @param  array<string,mixed> $inputs  Explicit Action input map; values are validated without reading process
     *                                      environment.
     * @return array               Dependency-fragment outcome and any confirmed created head identity.
     * @throws RuntimeException    When required authority, transaction evidence or a successful operation outcome cannot
     *                             be established.
     * @throws JsonException       When explicit JSON metadata or machine-readable validation output cannot be encoded or
     *                             decoded.
     */
    private function dependabot(ReleaseOptions $options, array $inputs): array
    {
        $alerts = json_decode(
            $this->text($inputs, 'security-alert-numbers', '[]'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($alerts) || ! array_is_list($alerts)) {
            throw new InvalidArgumentException('security-alert-numbers must be a JSON array of alert numbers.');
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

    /**
     * Maintains the same release line; Git and GitHub freshness/race rules belong to the shared service.
     *
     * @param  ReleaseOptions      $options Trusted consumer paths, rendering settings and repository selection for this
     *                                      operation.
     * @param  array<string,mixed> $inputs  Explicit Action input map; values are validated without reading process
     *                                      environment.
     * @return array               Managed PR synchronization result without claiming a tag or release was published.
     * @throws RuntimeException    When required authority, transaction evidence or a successful operation outcome cannot
     *                             be established.
     */
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

    /**
     * Requires immutable merge and signed-head proof when called as a post-PR publisher.
     *
     * @param  ReleaseOptions           $options Trusted consumer paths, rendering settings and repository selection for this
     *                                           operation.
     * @param  array<string,mixed>      $inputs  Explicit Action input map; values are validated without reading process
     *                                           environment.
     * @return array                    Verified publication summary for either a strict approved commit or an approved merged PR.
     * @throws RuntimeException         When required authority, transaction evidence or a successful operation outcome cannot
     *                                  be established.
     * @throws InvalidArgumentException When approved target or contextual PR identities are incomplete or inconsistent.
     */
    private function publish(ReleaseOptions $options, array $inputs): array
    {
        $target = $this->text($inputs, 'target-sha');

        if (! GitHubEvidence::sha($target)) {
            throw new InvalidArgumentException('The publish Action requires a complete approved target-sha.');
        }

        if ('' !== $this->text($inputs, 'pull-request')) {
            if (!$this->publication instanceof MergedPublicationServiceInterface) {
                throw new RuntimeException('The publisher does not support verified merged-PR publication.');
            }

            return $this->publication->publishMerged(
                $options,
                $this->number($inputs, 'pull-request'),
                $target,
                $this->text($inputs, 'expected-head-sha'),
                $this->text($inputs, 'base-branch', 'main'),
                $this->text($inputs, 'managed-branch', 'changelog/version'),
                $this->text($inputs, 'automation-actor', 'github-actions[bot]'),
                $this->boolean($inputs, 'dry-run'),
            )->summary();
        } elseif ('' !== $this->text($inputs, 'expected-head-sha')) {
            throw new InvalidArgumentException('expected-head-sha requires pull-request.');
        }

        return $this->publication->publish($options, $target, $this->boolean($inputs, 'dry-run'))->summary();
    }

    /**
     * Keeps historical maintenance local, with preview/check behavior matching the CLI.
     *
     * @param  ReleaseOptions           $options Trusted consumer paths, rendering settings and repository selection for this
     *                                           operation.
     * @param  array<string,mixed>      $inputs  Explicit Action input map; values are validated without reading process
     *                                           environment.
     * @return array                    Planned history summary with dry-run, verified, applied or unchanged outcome.
     * @throws InvalidArgumentException When explicit operation inputs or selected consumer settings are invalid.
     * @throws RuntimeException         When required authority, transaction evidence or a successful operation outcome cannot
     *                                  be established.
     */
    private function history(ReleaseOptions $options, array $inputs): array
    {
        $operation = $this->text($inputs, 'operation', 'backfill');
        $dryRun = $this->boolean($inputs, 'dry-run');
        $check = $this->boolean($inputs, 'check');

        if (! in_array($operation, ['backfill', 'format'], true) || ($dryRun && $check)) {
            throw new InvalidArgumentException(
                'History requires backfill or format and mutually exclusive dry-run/check.',
            );
        }
        $plan = $this->planner->plan($options, $operation);

        if ($check && ! $this->applier->isApplied($plan)) {
            throw new RuntimeException('Historical maintenance differs from the expected applied plan.');
        }
        $applied = ! $dryRun && ! $check && $this->applier->apply($plan);

        return [...$plan->summary(), 'status' => $dryRun ? 'dry-run' : ($check ? 'verified' : ($applied ? 'applied' : 'unchanged'))];
    }

    /**
     * Rejects ambiguous transport types instead of coercing booleans, arrays or numeric metadata.
     *
     * @param  array<string,mixed>      $inputs  Explicit Action input map; values are validated without reading process
     *                                           environment.
     * @param  string                   $key     Declared Action input name to read from the explicit transport map.
     * @param  string                   $default Fallback used only when the input key is absent, without coercing an explicit supplied
     *                                           value.
     * @return string                   Uncoerced NUL-free string, using the fallback only when the key is absent.
     * @throws InvalidArgumentException When explicit operation inputs or selected consumer settings are invalid.
     */
    private function text(array $inputs, string $key, string $default = ''): string
    {
        $value = array_key_exists($key, $inputs) ? $inputs[$key] : $default;

        if (! is_string($value) || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Action input ' . $key . ' must be a string without NUL.');
        }

        return $value;
    }

    /**
     * Accepts only explicit booleans from the CLI, Docker Actions and workflow_call.
     *
     * @param  array<string,mixed>      $inputs  Explicit Action input map; values are validated without reading process
     *                                           environment.
     * @param  string                   $key     Declared Action input name to read from the explicit transport map.
     * @param  bool                     $default Fallback used only when the input key is absent, without coercing an explicit supplied
     *                                           value.
     * @return bool                     Explicit boolean after accepting only true/false values or their literal CLI strings.
     * @throws InvalidArgumentException When explicit operation inputs or selected consumer settings are invalid.
     */
    private function boolean(array $inputs, string $key, bool $default = false): bool
    {
        $value = array_key_exists($key, $inputs) ? $inputs[$key] : $default;

        if (! in_array($value, [true, false, 'true', 'false'], true)) {
            throw new InvalidArgumentException('Action input ' . $key . ' must be true or false.');
        }

        return true === $value || 'true' === $value;
    }

    /**
     * Positive PR numbers use canonical decimal syntax and the host's safe integer range.
     *
     * @param  array<string,mixed>      $inputs Explicit Action input map; values are validated without reading process
     *                                          environment.
     * @param  string                   $key    Declared Action input name to read from the explicit transport map.
     * @return int                      Positive canonical integer within the current PHP integer range.
     * @throws InvalidArgumentException When explicit operation inputs or selected consumer settings are invalid.
     */
    private function number(array $inputs, string $key): int
    {
        $raw = $this->text($inputs, $key);
        $number = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (1 !== preg_match('/\A[1-9][0-9]*\z/', $raw) || false === $number) {
            throw new InvalidArgumentException('Action input ' . $key . ' must be a positive integer.');
        }

        return $number;
    }

    /**
     * A refused or raced write cannot be reported as a successful workflow.
     *
     * @param  string           $status      Operation outcome used to distinguish success from refusal or a possible partial write.
     * @param  list<string>     $diagnostics Controlled explanations for refusal, conflict or exceptional authorization
     *                                       state.
     * @return void             No value; refusal and conflict outcomes are converted into a failed workflow operation.
     * @throws RuntimeException When required authority, transaction evidence or a successful operation outcome cannot
     *                          be established.
     */
    private function assertStatus(string $status, array $diagnostics): void
    {
        if (in_array($status, ['refused', 'conflict'], true)) {
            throw new RuntimeException('Automation ' . $status . ': ' . implode(' ', $diagnostics));
        }
    }
}
