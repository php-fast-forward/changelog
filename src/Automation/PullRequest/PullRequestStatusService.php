<?php

declare(strict_types=1);

namespace FastForward\Changelog\Automation\PullRequest;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestAuthorization;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validation\CheckServiceInterface;
use FastForward\Changelog\Validation\ValidationReport;
use RuntimeException;

/** Checks immutable PR data and optionally records a commit status; custom consumer PHP is never evaluated. */
final readonly class PullRequestStatusService implements PullRequestStatusServiceInterface
{
    public const string CONTEXT = 'Fast Forward Changelog';

    /**
     * Injects every authority, Git and validation boundary; construction performs no I/O.
     *
     * @param GitHubClientInterface      $github Authenticated, replaceable GitHub API boundary for scoped reads and writes.
     * @param GitRepositoryInterface     $git    Replaceable repository boundary for immutable blobs, identities, ancestry and
     *                                           local Git operations.
     * @param PullRequestPolicyInterface $policy Independent verifier for privileged human grants and signed
     *                                           generated-commit ownership.
     * @param CheckServiceInterface      $checks Contribution validator that preserves inherited fragments and enforces
     *                                           verified authorization.
     */
    public function __construct(
        private GitHubClientInterface $github,
        private GitRepositoryInterface $git,
        private PullRequestPolicyInterface $policy,
        private CheckServiceInterface $checks,
    ) {}

    /**
     * Discards proven obsolete snapshots; unavailable evidence still fails and never publishes a final status.
     *
     * @param  ReleaseOptions   $options          Trusted consumer paths, rendering settings and repository selection for this
     *                                            operation.
     * @param  int              $number           Positive pull-request number bound to the supplied immutable head and base.
     * @param  string           $head             Immutable managed or contribution head selected for the current operation.
     * @param  string           $base             Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @param  string           $baseBranch       Trusted release-line branch that supplies the fresh source base.
     * @param  string           $managedBranch    Exclusive automation branch reserved for the generated version PR.
     * @param  string           $actor            Canonical GitHub account login whose current identity must authorize the operation.
     * @param  string           $waiverLabel      Exact label whose latest privileged human grant may waive contribution presence.
     * @param  string           $maintenanceLabel Exact label whose latest privileged human grant may authorize history
     *                                            maintenance.
     * @param  bool             $publishStatus    Whether to write the fixed commit status after verifying the exact PR snapshot.
     * @return array            Valid/invalid contribution feedback, or an obsolete result without comment or final status.
     * @throws RuntimeException When immutable PR, authority, committed-blob or status evidence cannot be confirmed.
     */
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
        if (! $this->fresh($options, $number, $head, $base, $baseBranch)) {
            return $this->obsolete($number, $head, $base);
        }

        if ($publishStatus) {
            $this->status($options, $head, 'pending');
        }
        $authorization = $this->policy->inspect(
            $options, $number, $managedBranch, $actor, $waiverLabel, $maintenanceLabel,
        );

        if ($authorization->headSha !== $head) {
            if (! $this->fresh($options, $number, $head, $base, $baseBranch)) {
                return $this->obsolete($number, $head, $base);
            }

            throw new RuntimeException('PR authorization is unavailable or belongs to another revision.');
        }
        $report = $this->checks->check(
            $options, $base, $authorization->centralChangeAuthorized,
            $authorization->waiverAuthorized, $authorization->kind,
        );
        $fragments = $this->newFragments($options, $base, $report->changesets);

        if ($report->isValid()) {
            $this->assertCommittedFragments($options, $head, $report);
        }
        $valid = $report->isValid();
        $body = $this->comment($head, $base, $fragments, $authorization, $report, $valid);
        if (! $this->fresh($options, $number, $head, $base, $baseBranch)) {
            return $this->obsolete($number, $head, $base);
        }

        if ($publishStatus) {
            $this->status($options, $head, $valid ? 'success' : 'failure');
        }

        return ['status' => $valid ? 'valid' : 'invalid', 'pull_request' => $number,
            'head_sha' => $head, 'base_sha' => $base, 'fragments' => count($fragments),
            'kind' => $authorization->kind, 'comment_body' => $body, 'errors' => $report->errors,
            'diagnostics' => $authorization->diagnostics];
    }

    /**
     * Selects valid fragments newly introduced by the contribution rather than the inherited inventory.
     *
     * @param  ReleaseOptions   $options    Trusted consumer paths, rendering settings and repository selection for this
     *                                      operation.
     * @param  string           $base       Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @param  list<Changeset>  $changesets Validated fragment values retained in their deterministic report or release
     *                                      order.
     * @return list<Changeset>  Valid new contribution fragments in report order, excluding the inherited inventory.
     * @throws RuntimeException When immutable PR, authority, committed-blob or status evidence cannot be confirmed.
     */
    private function newFragments(ReleaseOptions $options, string $base, array $changesets): array
    {
        $added = [];
        $prefix = $options->fragmentDirectory . '/';

        foreach ($this->git->changesSince($options->workingDirectory, $base) as $change) {
            if (in_array($change['status'][0], ['A', 'C'], true)
                || ('R' === $change['status'][0] && ! str_starts_with($change['previous'] ?? '', $prefix))) {
                $added[$change['path']] = true;
            }
        }
        $fragments = [];

        foreach ($changesets as $fragment) {
            if (isset($added[$prefix . $fragment->id])) {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    /**
     * Binds a successful validation to committed regular blobs before publishing a final status.
     *
     * @param  ReleaseOptions   $options Trusted consumer paths, rendering settings and repository selection for this
     *                                   operation.
     * @param  string           $head    Immutable managed or contribution head selected for the current operation.
     * @param  ValidationReport $report  Aggregate validation result, including accepted fragments, diagnostics and exact
     *                                   content hashes.
     * @return void             No value; successful validation hashes must match every committed regular fragment blob.
     * @throws RuntimeException When immutable PR, authority, committed-blob or status evidence cannot be confirmed.
     */
    private function assertCommittedFragments(
        ReleaseOptions $options,
        string $head,
        ValidationReport $report,
    ): void {
        $prefix = $options->fragmentDirectory . '/';

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

    /**
     * Renders bounded inert Markdown from an already validated immutable contribution snapshot.
     *
     * @param  string                   $head          Immutable managed or contribution head selected for the current operation.
     * @param  string                   $base          Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @param  list<Changeset>          $fragments     Valid fragments newly introduced by this contribution, excluding inherited
     *                                                 pending notes.
     * @param  PullRequestAuthorization $authorization Verified PR policy result bound to the exact checked head.
     * @param  ValidationReport         $report        Aggregate validation result, including accepted fragments, diagnostics and exact
     *                                                 content hashes.
     * @param  bool                     $valid         Already computed contribution validity used consistently for the preview and final status.
     * @return string                   Bounded inert Markdown preview with immutable identities and a consistent validation outcome.
     */
    private function comment(
        string $head,
        string $base,
        array $fragments,
        PullRequestAuthorization $authorization,
        ValidationReport $report,
        bool $valid,
    ): string {
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

        return $body;
    }

    /**
     * Distinguishes proven PR movement/closure from unavailable or malformed API evidence; forks remain inert data.
     *
     * @param  ReleaseOptions   $options Trusted consumer paths, rendering settings and repository selection for this
     *                                   operation.
     * @param  int              $number  Positive pull-request number bound to the supplied immutable head and base.
     * @param  string           $head    Immutable managed or contribution head selected for the current operation.
     * @param  string           $base    Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @param  string           $branch  Validated branch name used for the exact remote reference or release line.
     * @return bool             Whether the verified PR remains open at the requested head, base and release line.
     * @throws RuntimeException When the API cannot supply a complete PR identity and recognized state.
     */
    private function fresh(
        ReleaseOptions $options,
        int $number,
        string $head,
        string $base,
        string $branch,
    ): bool {
        $pr = $this->github->request('GET', '/repos/' . $options->repository . '/pulls/' . $number);

        // Check closed snapshots against the same identity contract without weakening the shared open-PR guard.
        if (null === $pr || ! in_array($pr['state'] ?? null, ['open', 'closed'], true)
            || ! GitHubEvidence::pullRequest([...$pr, 'state' => 'open'], $options->repository, $number, false)
            || ! is_string($pr['base']['ref'] ?? null) || '' === $pr['base']['ref']) {
            throw new RuntimeException(
                'PR status snapshot evidence is unavailable or malformed; no final result was published.',
            );
        }

        return 'open' === $pr['state'] && $pr['head']['sha'] === $head
            && $pr['base']['sha'] === $base && $pr['base']['ref'] === $branch;
    }

    /**
     * Reports a discarded snapshot without asserting contribution validity or producing publishable feedback.
     *
     * @param  int    $number Original positive PR number whose live identity was verified.
     * @param  string $head   Original immutable head, never replaced with a newer revision.
     * @param  string $base   Original immutable comparison base, retained for diagnostics.
     * @return array  Explicit obsolete result; any earlier pending status remains untouched.
     */
    private function obsolete(int $number, string $head, string $base): array
    {
        return ['status' => 'obsolete', 'pull_request' => $number, 'head_sha' => $head, 'base_sha' => $base,
            'diagnostics' => ['The PR closed or its head, base or release line changed; validation was discarded.']];
    }

    /**
     * Uses a fixed context and messages, without forwarding untrusted prose or external status URLs.
     *
     * @param  ReleaseOptions   $options Trusted consumer paths, rendering settings and repository selection for this
     *                                   operation.
     * @param  string           $head    Immutable managed or contribution head selected for the current operation.
     * @param  string           $state   Publication or commit-status state selected by the owning operation.
     * @return void             No value; GitHub must confirm the fixed context and requested state at the exact head.
     * @throws RuntimeException When immutable PR, authority, committed-blob or status evidence cannot be confirmed.
     */
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

    /**
     * Bounds untrusted Markdown, escapes formatting/HTML and suppresses mention and autolink expansion.
     *
     * @param  string $text Untrusted display prose to bound and escape without activating Markdown, HTML or links.
     * @return string Bounded valid UTF-8 literal with neutralized Markdown, HTML, mentions and autolinks.
     */
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
