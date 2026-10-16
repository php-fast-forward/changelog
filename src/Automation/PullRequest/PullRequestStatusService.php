<?php

declare(strict_types=1);

namespace FastForward\Changelog\Automation\PullRequest;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validation\CheckServiceInterface;
use RuntimeException;

/** Checks immutable PR data and optionally records a commit status; custom consumer PHP is never evaluated. */
final readonly class PullRequestStatusService implements PullRequestStatusServiceInterface
{
    public const string CONTEXT = 'Fast Forward Changelog';

    /** Injects every authority, Git and validation boundary; construction performs no I/O. */
    public function __construct(
        private GitHubClientInterface $github,
        private GitRepositoryInterface $git,
        private PullRequestPolicyInterface $policy,
        private CheckServiceInterface $checks,
    ) {}

    /** Rejects mismatched snapshots before writes and leaves a blocking pending status on uncertain execution. */
    public function inspect(
        ReleaseOptions $options,
        int $number,
        string $head,
        string $base,
        string $baseBranch,
        string $managedBranch,
        string $actor,
        string $waiverLabel,
        string $maintenanceLabel,
        bool $publishStatus,
    ): array {
        if (! GitHubEvidence::repository($options->repository) || $number < 1
            || ! GitHubEvidence::sha($head) || ! GitHubEvidence::sha($base)
            || '' === $baseBranch || 'keep-a-changelog' !== $options->template
            || 'HEAD' !== $options->baseRef
            || $this->git->repositoryRoot($options->workingDirectory) !== rtrim(
                str_replace('\\', '/', $options->workingDirectory), '/',
            )
            || $this->git->resolveRef($options->workingDirectory) !== $head) {
            throw new RuntimeException(
                'PR status requires the repository-root checkout at the exact head, a complete base and the built-in template.',
            );
        }
        $this->fresh($options, $number, $head, $base, $baseBranch);
        if ($publishStatus) {
            $this->status($options, $head, 'pending');
        }
        $authorization = $this->policy->inspect(
            $options, $number, $managedBranch, $actor, $waiverLabel, $maintenanceLabel,
        );
        if ($authorization->headSha !== $head) {
            throw new RuntimeException('PR authorization is unavailable or belongs to another revision.');
        }
        $report = $this->checks->check(
            $options, $base, $authorization->centralChangeAuthorized,
            $authorization->waiverAuthorized, $authorization->kind,
        );
        $added = [];
        $prefix = $options->fragmentDirectory . '/';
        foreach ($this->git->changesSince($options->workingDirectory, $base) as $change) {
            if (in_array($change['status'][0], ['A', 'C'], true)
                || ('R' === $change['status'][0] && ! str_starts_with($change['previous'] ?? '', $prefix))) {
                $added[$change['path']] = true;
            }
        }
        $fragments = [];
        foreach ($report->changesets as $fragment) {
            if (isset($added[$prefix . $fragment->id])) {
                $fragments[] = $fragment;
            }
        }
        if ($report->isValid()) {
            // A writable status must describe committed bytes, never a locally edited validation fixture.
            foreach ($this->git->filesAt($options->workingDirectory, $head, $options->fragmentDirectory) as $file) {
                if (! str_ends_with($file['path'], '.md') || $file['path'] === $prefix . 'AGENTS.md') {
                    continue;
                }
                $contents = $this->git->readFileAt($options->workingDirectory, $head, $file['path']);
                $path = rtrim($options->workingDirectory, '/') . '/' . $file['path'];
                if ('100644' !== $file['mode'] && '100755' !== $file['mode']
                    || null === $contents || ($report->hashes[$path] ?? null) !== hash('sha256', $contents)) {
                    throw new RuntimeException('The checked fragments do not match their committed regular blobs.');
                }
            }
        }
        $valid = $report->isValid();
        $body = "## Changelog\n\n" . ($valid ? '✅ Contribution check passed.' : '❌ Contribution check failed.')
            . "\n\nHead: `" . $head . "`\n\nBase: `" . $base . "`\n\n"
            . 'Contribution: ' . count($fragments) . ' new valid fragment(s). Policy: ' . $authorization->kind . ".\n";
        foreach (array_slice($fragments, 0, 20) as $fragment) {
            $body .= "\n- " . $this->literal($fragment->id) . ' — ' . $fragment->category->value . ', '
                . $fragment->effectiveImpact()->value . "\n\n    " . $this->literal($fragment->description) . "\n";
        }
        $diagnostics = $authorization->diagnostics;
        foreach ($report->errors as $path => $messages) {
            foreach ($messages as $message) {
                $diagnostics[] = $path . ': ' . $message;
            }
        }
        if ([] !== $diagnostics) {
            $body .= "\n### Diagnostics\n";
            foreach (array_slice($diagnostics, 0, 20) as $message) {
                $body .= "\n- " . $this->literal($message) . "\n";
            }
        }
        if (count($fragments) > 20 || count($diagnostics) > 20) {
            $body .= "\nThe preview is limited to 20 fragments and 20 diagnostics. See the workflow for full validation.\n";
        }
        if (! $valid) {
            $body .= "\nAdd or correct a unique `.changelog/*.md` fragment and rerun the check. "
                . "Ordinary contributions must preserve `CHANGELOG.md` and inherited fragments.\n";
        }
        $this->fresh($options, $number, $head, $base, $baseBranch);
        if ($publishStatus) {
            $this->status($options, $head, $valid ? 'success' : 'failure');
        }

        return ['status' => $valid ? 'valid' : 'invalid', 'pull_request' => $number,
            'head_sha' => $head, 'base_sha' => $base, 'fragments' => count($fragments),
            'kind' => $authorization->kind, 'comment_body' => $body, 'errors' => $report->errors,
            'diagnostics' => $authorization->diagnostics];
    }

    /** Confirms the same open PR/head/base/release line; fork data is allowed but never executed. */
    private function fresh(ReleaseOptions $options, int $number, string $head, string $base, string $branch): void
    {
        $pr = $this->github->request('GET', '/repos/' . $options->repository . '/pulls/' . $number);
        if (! GitHubEvidence::pullRequest($pr, $options->repository, $number, false)
            || $pr['head']['sha'] !== $head || $pr['base']['sha'] !== $base || ($pr['base']['ref'] ?? null) !== $branch) {
            throw new RuntimeException(
                'PR status snapshot is stale, closed or targets another release line; no final result was published.',
            );
        }
    }

    /** Uses a fixed context and messages, without forwarding untrusted prose or external status URLs. */
    private function status(ReleaseOptions $options, string $head, string $state): void
    {
        $result = $this->github->request('POST', '/repos/' . $options->repository . '/statuses/' . $head, [
            'context' => self::CONTEXT, 'state' => $state,
            'description' => match ($state) {
                'pending' => 'Checking the exact PR contribution.',
                'success' => 'Changelog contribution is valid.',
                'failure' => 'Changelog contribution needs changes; see the PR comment and workflow.',
            },
        ]);
        if (($result['context'] ?? null) !== self::CONTEXT || ($result['state'] ?? null) !== $state) {
            throw new RuntimeException('GitHub did not confirm the requested changelog commit status.');
        }
    }

    /** Bounds untrusted Markdown, escapes formatting/HTML and suppresses mention and autolink expansion. */
    private function literal(string $text): string
    {
        $text = preg_replace('/[\x00-\x1f\x7f]/', ' ', substr($text, 0, 800));
        $text = preg_replace('/([\\\\`*_{}\[\]()#+!|~-])/', '\\\\$1', $text);
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = str_replace(['@', ':', '.'], ['@' . "\u{200B}", '&#58;', '&#46;'], $text);
        $text = substr($text, 0, 800);
        // ENT_SUBSTITUTE produced valid UTF-8; truncation can split only its final code point (at most three cuts).
        while (1 !== preg_match('//u', $text)) {
            $text = substr($text, 0, -1);
        }

        return preg_replace('/&[^;]*\z/', '', $text);
    }
}
