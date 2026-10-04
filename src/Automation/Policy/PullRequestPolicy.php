<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Automation\Policy;

use FastForward\Changelog\Automation\Policy\Factory\PullRequestAuthorizationFactoryInterface;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use Throwable;

/** Authorizes human grants and signed generated history using immutable GitHub account evidence. */
final readonly class PullRequestPolicy implements PullRequestPolicyInterface
{
    /** Injects API, result construction and value boundaries; construction has no external effects. */
    public function __construct(private GitHubClientInterface $github, private PullRequestAuthorizationFactoryInterface $results) {}

    /** Returns controlled diagnostics on inaccessible or inconsistent evidence, without exposing API secrets. */
    public function inspect(ReleaseOptions $options, int $prNumber, string $managedBranch = 'changelog/version', string $automationActor = 'github-actions[bot]', string $waiverLabel = 'changelog-not-required', string $maintenanceLabel = 'changelog-maintenance'): PullRequestAuthorization
    {
        $sha = null;
        try {
            if (! GitHubEvidence::repository($options->repository) || $prNumber < 1 || '' === $waiverLabel || '' === $maintenanceLabel || $waiverLabel === $maintenanceLabel) {
                return $this->results->create(false, false, 'ordinary', ['PR policy requires an explicit repository, positive PR and distinct exception labels.']);
            }
            $repo = $options->repository;
            $pr = $this->github->request('GET', '/repos/' . $repo . '/pulls/' . $prNumber);
            if (! GitHubEvidence::pullRequest($pr, $repo, $prNumber, false)) {
                return $this->results->create(false, false, 'ordinary', ['An open PR targeting the configured repository with a complete head snapshot is required.']);
            }
            $sha = $pr['head']['sha'];
            $diagnostics = [];
            $labels = array_column($pr['labels'] ?? [], 'name');
            $waiver = false;
            $maintenance = false;
            if (in_array($waiverLabel, $labels, true) || in_array($maintenanceLabel, $labels, true)) {
                $timeline = $this->github->paginate('/repos/' . $repo . '/issues/' . $prNumber . '/timeline');
                if (in_array($waiverLabel, $labels, true)) {
                    $waiver = $this->humanGrant($repo, $timeline, $waiverLabel);
                    if (! $waiver) {
                        $diagnostics[] = 'Changelog waiver lacks a current maintain/admin human grant.';
                    }
                }
                if (in_array($maintenanceLabel, $labels, true)) {
                    $maintenance = $this->humanGrant($repo, $timeline, $maintenanceLabel);
                    if (! $maintenance) {
                        $diagnostics[] = 'Central-history maintenance lacks a current maintain/admin human grant.';
                    }
                }
            }
            $managed = false;
            if (GitHubEvidence::pullRequest($pr, $repo, $prNumber) && $pr['head']['ref'] === $managedBranch && GitHubEvidence::identity($pr['user'] ?? null, $automationActor, 'Bot')) {
                $managed = $this->managedProof($options, $pr, $automationActor);
                if (! $managed) {
                    $diagnostics[] = 'Managed version PR requires a fresh source base, signed Bot commit and validated file scope; resynchronize it before merge.';
                }
            }
            $kind = $managed ? 'managed-version' : ($maintenance ? 'maintenance' : ($waiver ? 'waiver' : 'ordinary'));
            return $this->results->create($waiver, $maintenance || $managed, $kind, $diagnostics, $sha);
        } catch (Throwable) {
            return $this->results->create(false, false, 'ordinary', ['GitHub PR authority could not be verified; response details were withheld.'], $sha);
        }
    }

    /** Requires the chronologically latest exact-label transition to be a grant by a currently privileged human. */
    private function humanGrant(string $repository, array $timeline, string $label): bool
    {
        $latest = null;
        foreach ($timeline as $event) {
            if (! is_array($event) || ($event['label']['name'] ?? null) !== $label || ! in_array($event['event'] ?? null, ['labeled', 'unlabeled'], true)) {
                continue;
            }
            if (! is_int($event['id'] ?? null) || ! is_string($event['created_at'] ?? null)
                || 1 !== preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $event['created_at'])
                || false === strtotime($event['created_at'])
                || gmdate('Y-m-d\TH:i:s\Z', strtotime($event['created_at'])) !== $event['created_at']
            ) {
                return false;
            }
            if (null === $latest || [$event['created_at'], $event['id']] > [$latest['created_at'], $latest['id']]) {
                $latest = $event;
            }
        }
        if (null === $latest || $latest['event'] !== 'labeled' || ! is_string($latest['actor']['login'] ?? null)
            || ! GitHubEvidence::identity($latest['actor'], $latest['actor']['login'], 'User')
        ) {
            return false;
        }
        $actor = $latest['actor'];
        $permission = $this->github->request('GET', '/repos/' . $repository . '/collaborators/' . rawurlencode($actor['login']) . '/permission');
        return null !== $permission && GitHubEvidence::identity($permission['user'] ?? null, $actor['login'], 'User', $actor['id'])
            && (('maintain' === ($permission['role_name'] ?? null) && 'write' === ($permission['permission'] ?? null))
                || ('admin' === ($permission['role_name'] ?? null) && 'admin' === ($permission['permission'] ?? null)));
    }

    /** Requires the live PR base to match its generated source before allowing merge; updater ownership may remain older. */
    private function managedProof(ReleaseOptions $options, array $pr, string $actor): bool
    {
        $account = $this->github->request('GET', '/users/' . rawurlencode($actor));
        $commit = $this->github->request('GET', '/repos/' . $options->repository . '/commits/' . $pr['head']['sha']);
        $message = $commit['commit']['message'] ?? null;
        return is_string($message)
            && 1 === preg_match_all('/^Changelog-Base: ((?:[a-f0-9]{40}|[a-f0-9]{64}))$/m', $message, $matches)
            && $matches[1][0] === $pr['base']['sha']
            && GitHubEvidence::identity($account, $actor, 'Bot', $pr['user']['id'])
            && $this->inspectHead($options, $pr['head']['sha'], $actor, $pr['base']['sha']);
    }

    /**
     * Accepts an older generated base only when GitHub proves ancestry to the current base and head.
     * Every changed file MUST belong to the generated transaction; unknown files, renames and truncated
     * comparison scope fail closed. Raw commit emails, plan IDs and PR prose never prove identity.
     */
    public function inspectHead(ReleaseOptions $options, string $headSha, string $automationActor = 'github-actions[bot]', ?string $baseSha = null): bool
    {
        try {
            if (! GitHubEvidence::repository($options->repository) || ! GitHubEvidence::sha($headSha) || (null !== $baseSha && ! GitHubEvidence::sha($baseSha))) {
                return false;
            }
            $repository = $options->repository;
            $account = $this->github->request('GET', '/users/' . rawurlencode($automationActor));
            if (! GitHubEvidence::identity($account, $automationActor, 'Bot')) {
                return false;
            }
            $commit = $this->github->request('GET', '/repos/' . $repository . '/commits/' . $headSha);
            if (null === $commit || ($commit['sha'] ?? null) !== $headSha
                || ! GitHubEvidence::identity($commit['author'] ?? null, $automationActor, 'Bot', $account['id'])
                || true !== ($commit['commit']['verification']['verified'] ?? null)
                || 'valid' !== ($commit['commit']['verification']['reason'] ?? null)
                || ! $this->trustedCommitter($repository, $headSha, $commit['committer'] ?? null, $automationActor, $account['id'])
            ) {
                return false;
            }
            $message = $commit['commit']['message'] ?? null;
            if (! is_string($message)) {
                return false;
            }
            $data = [];
            foreach (['Base' => 'base_sha', 'Plan' => 'id', 'Output' => 'after_changelog_sha256', 'Options' => 'options_sha256'] as $trailer => $key) {
                if (1 !== preg_match_all('/^Changelog-' . $trailer . ':.*$/m', $message)
                    || 1 !== preg_match_all('/^Changelog-' . $trailer . ': (.*)$/m', $message, $matches)
                    || 1 !== preg_match('Base' === $trailer ? '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D' : '/^[a-f0-9]{64}$/D', $matches[1][0])) {
                    return false;
                }
                $data[$key] = $matches[1][0];
            }
            if (! GitHubEvidence::sha($data['base_sha']) || strlen($data['id']) !== 64
                || $data['options_sha256'] !== $options->evidenceHash()) {
                return false;
            }
            $central = GitHubEvidence::content($this->github->request('GET', GitHubEvidence::contentsPath($repository, $options->changelogFile, $headSha)));
            if (null === $central || hash('sha256', $central) !== $data['after_changelog_sha256']) {
                return false;
            }
            $scope = null;
            foreach (array_unique([$headSha, $baseSha ?? $headSha]) as $descendant) {
                if ($data['base_sha'] === $descendant) {
                    continue;
                }
                $comparison = $this->github->request('GET', '/repos/' . $repository . '/compare/' . $data['base_sha'] . '...' . $descendant);
                if (null === $comparison || ($comparison['merge_base_commit']['sha'] ?? null) !== $data['base_sha']
                    || ! in_array($comparison['status'] ?? null, ['ahead', 'identical'], true)
                ) {
                    return false;
                }
                if ($descendant === $headSha) {
                    $scope = $comparison['files'] ?? null;
                }
            }
            return $this->managedScope($options, $headSha, $data, $scope);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Accepts the authenticated Bot or GitHub's exact web committer backed by its verified signing key.
     * A valid signature by an arbitrary user cannot establish ownership; the platform alternative
     * requires an immutable GraphQL commit proof and independently corroborated web-flow identity.
     * @see https://docs.github.com/en/graphql/reference/git#gitsignature
     */
    private function trustedCommitter(string $repository, string $headSha, mixed $committer, string $automationActor, int $actorId): bool
    {
        if (GitHubEvidence::identity($committer, $automationActor, 'Bot', $actorId)) {
            return true;
        }
        if (! GitHubEvidence::identity($committer, 'web-flow', 'User', 19864447)
            || ! GitHubEvidence::identity($this->github->request('GET', '/users/web-flow'), 'web-flow', 'User', 19864447)
        ) {
            return false;
        }
        [$owner, $name] = explode('/', $repository, 2);
        $proof = $this->github->request('POST', '/graphql', [
            'query' => 'query ChangelogPlatformSignature($owner: String!, $name: String!, $oid: GitObjectID!) { repository(owner: $owner, name: $name) { object(oid: $oid) { ... on Commit { oid signature { isValid state wasSignedByGitHub } } } } }',
            'variables' => ['owner' => $owner, 'name' => $name, 'oid' => $headSha],
        ]);
        if (null === $proof || array_key_exists('errors', $proof)) {
            return false;
        }
        $object = $proof['data']['repository']['object'] ?? null;
        return is_array($object) && ($object['oid'] ?? null) === $headSha
            && true === ($object['signature']['isValid'] ?? null)
            && 'VALID' === ($object['signature']['state'] ?? null)
            && true === ($object['signature']['wasSignedByGitHub'] ?? null);
    }

    /** Proves a complete central-plus-deletions diff from immutable source-base blobs. */
    private function managedScope(ReleaseOptions $options, string $headSha, array $data, mixed $scope): bool
    {
        if (! is_array($scope) || ! array_is_list($scope) || count($scope) >= 300) {
            return false;
        }
        $seen = [];
        $removed = [];
        foreach ($scope as $file) {
            $path = $file['filename'] ?? null;
            if (! is_string($path) || isset($seen[$path]) || isset($file['previous_filename'])) {
                return false;
            }
            $seen[$path] = true;
            if ($path === $options->changelogFile) {
                if (! in_array($file['status'] ?? null, ['added', 'modified'], true)) {
                    return false;
                }
                continue;
            }
            $prefix = $options->fragmentDirectory . '/';
            if (! str_starts_with($path, $prefix)
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($prefix)))
                || 'removed' !== ($file['status'] ?? null)) {
                return false;
            }
            $before = GitHubEvidence::content($this->github->request('GET', GitHubEvidence::contentsPath($options->repository, $path, $data['base_sha'])));
            $after = $this->github->request('GET', GitHubEvidence::contentsPath($options->repository, $path, $headSha));
            if (null === $before || null !== $after) {
                return false;
            }
            $removed[] = $path;
        }
        if (! isset($seen[$options->changelogFile])) {
            return false;
        }
        $entries = $this->github->request('GET', GitHubEvidence::contentsPath($options->repository, $options->fragmentDirectory, $data['base_sha']));
        if (null === $entries) {
            return [] === $removed;
        }
        if (! array_is_list($entries) || count($entries) >= 1000) {
            return false;
        }
        $pending = [];
        foreach ($entries as $entry) {
            $path = $entry['path'] ?? null;
            if (! is_string($path)) {
                return false;
            }
            if (! str_ends_with($path, '.md') || $path === $options->fragmentDirectory . '/AGENTS.md') {
                continue;
            }
            $prefix = $options->fragmentDirectory . '/';
            if ('file' !== ($entry['type'] ?? null) || ! str_starts_with($path, $prefix)
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($prefix)))) {
                return false;
            }
            $pending[] = $path;
        }
        sort($pending, SORT_STRING);
        sort($removed, SORT_STRING);
        return $pending === $removed;
    }
}
