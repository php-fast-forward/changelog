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
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use Throwable;

/** Authorizes human grants and signed generated history using immutable GitHub account evidence. */
final readonly class PullRequestPolicy implements PullRequestPolicyInterface
{
    /** Injects API, validated receipt and value boundaries; construction has no external effects. */
    public function __construct(private GitHubClientInterface $github, private ReceiptCodecInterface $receipts, private PullRequestAuthorizationFactoryInterface $results) {}

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
                    $diagnostics[] = 'Managed version PR lacks a signed Bot commit and matching head receipt.';
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

    /** Verifies the PR creator independently, then shares immutable-head ownership proof with the updater. */
    private function managedProof(ReleaseOptions $options, array $pr, string $actor): bool
    {
        $account = $this->github->request('GET', '/users/' . rawurlencode($actor));
        return GitHubEvidence::identity($account, $actor, 'Bot', $pr['user']['id'])
            && $this->inspectHead($options, $pr['head']['sha'], $actor, $pr['base']['sha']);
    }

    /**
     * Accepts an older generated base only when GitHub proves ancestry to the current base and head.
     * Every changed file MUST belong to the saved transaction; unknown files, renames and truncated
     * comparison scope fail closed. Raw commit emails, receipt IDs and PR prose never prove identity.
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
                || ! GitHubEvidence::identity($commit['committer'] ?? null, $automationActor, 'Bot', $account['id'])
                || true !== ($commit['commit']['verification']['verified'] ?? null)
                || 'valid' !== ($commit['commit']['verification']['reason'] ?? null)
            ) {
                return false;
            }
            $receiptPath = $options->fragmentDirectory . '/release-plan.json';
            $raw = GitHubEvidence::content($this->github->request('GET', GitHubEvidence::contentsPath($repository, $receiptPath, $headSha)));
            if (null === $raw) {
                return false;
            }
            $data = $this->receipts->decode($raw)->data;
            foreach (['repository' => $repository, 'changelog_file' => $options->changelogFile, 'fragment_directory' => $options->fragmentDirectory, 'locale' => $options->locale, 'template' => $options->template, 'tag_prefix' => $options->tagPrefix] as $key => $value) {
                if (($data[$key] ?? null) !== $value) {
                    return false;
                }
            }
            if (! is_string($data['base_sha'] ?? null) || ! GitHubEvidence::sha($data['base_sha'])
                || ! is_string($data['id'] ?? null) || ! is_string($commit['commit']['message'] ?? null)
                || ! in_array('Changelog-Plan: ' . $data['id'], explode("\n", $commit['commit']['message']), true)
            ) {
                return false;
            }
            $central = GitHubEvidence::content($this->github->request('GET', GitHubEvidence::contentsPath($repository, $options->changelogFile, $headSha)));
            if (null === $central || $central !== ($data['changelog_contents'] ?? null)
                || hash('sha256', $central) !== ($data['after_changelog_sha256'] ?? null)
            ) {
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

    /** Checks complete comparison scope and each removed fragment's exact bytes at the receipt base. */
    private function managedScope(ReleaseOptions $options, string $headSha, array $data, mixed $scope): bool
    {
        if (! is_array($scope) || ! array_is_list($scope) || count($scope) >= 300 || ! is_array($data['consumed'] ?? null)) {
            return false;
        }
        $receiptPath = $options->fragmentDirectory . '/release-plan.json';
        $seen = [];
        foreach ($scope as $file) {
            $path = $file['filename'] ?? null;
            if (! is_string($path) || isset($seen[$path]) || isset($file['previous_filename'])) {
                return false;
            }
            $seen[$path] = true;
            if (in_array($path, [$options->changelogFile, $receiptPath], true)) {
                if (! in_array($file['status'] ?? null, ['added', 'modified'], true)) {
                    return false;
                }
                continue;
            }
            if (! isset($data['consumed'][$path]) || ($file['status'] ?? null) !== 'removed') {
                return false;
            }
        }
        if (! isset($seen[$receiptPath])) {
            return false;
        }
        foreach ($data['consumed'] as $path => $hash) {
            $before = GitHubEvidence::content($this->github->request('GET', GitHubEvidence::contentsPath($options->repository, $path, $data['base_sha'])));
            $after = $this->github->request('GET', GitHubEvidence::contentsPath($options->repository, $path, $headSha));
            if (! isset($seen[$path]) || null === $before || hash('sha256', $before) !== $hash || null !== $after) {
                return false;
            }
        }
        $before = GitHubEvidence::content($this->github->request('GET', GitHubEvidence::contentsPath($options->repository, $options->changelogFile, $data['base_sha'])));
        return ($data['before_changelog_sha256'] ?? null) === (null === $before ? null : hash('sha256', $before));
    }
}
