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

namespace FastForward\Changelog\Automation\VersionPullRequest;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestExceptionFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactoryInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestResultFactoryInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\Release\Factory\ReleasePlanFactoryInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface;
use Throwable;

/** Rebuilds managed output on the newest approved base while retaining fast-forward ancestry. */
final readonly class VersionPullRequestService implements VersionPullRequestServiceInterface
{
    /** Injects all Git, API, planning, ownership and construction boundaries for isolated unit tests. */
    public function __construct(
        private GitHubClientInterface $github,
        private GitRepositoryInterface $git,
        private ReleasePlannerInterface $planner,
        private ReceiptCodecInterface $receipts,
        private PackagePathResolverInterface $paths,
        private PullRequestPolicyInterface $policy,
        private VersionPullRequestInputFactoryInterface $inputs,
        private VersionPullRequestResultFactoryInterface $results,
        private VersionPullRequestExceptionFactoryInterface $exceptions,
        private ReleaseInputEvidenceValidatorInterface $inputEvidence,
        private HistoryCodecInterface $history,
        private TemplateResolverInterface $templates,
        private HistoryReleaseFactoryInterface $releases,
        private ReleasePlanFactoryInterface $plans,
    ) {}

    /**
     * Plans at the fresh base and resolves version placeholders in the validated presentation title.
     * Title-only updates retain the signed head and plan; all writes require current ownership and scope proof.
     */
    public function synchronize(ReleaseOptions $options, VersionPullRequestInput $input): VersionPullRequestResult
    {
        $plan = null;
        $pr = null;
        $head = null;
        $writing = false;
        try {
            $input = $this->inputs->create(
                $input->baseBranch,
                $input->managedBranch,
                $input->automationActor,
                $input->title,
                $input->dryRun,
            );
            $this->require(
                GitHubEvidence::repository($options->repository),
                'Version PR requires an explicit trusted repository.',
            );
            $this->require(
                $this->git->repositoryRoot($options->workingDirectory) === rtrim(
                    str_replace('\\', '/', $options->workingDirectory),
                    '/',
                ),
                'Version PR requires repository-root workingDirectory and repository-relative managed paths.',
            );
            $repo = $options->repository;
            $base = $this->ref($repo, $input->baseBranch);
            $this->require(null !== $base, 'The configured base branch is unavailable.');
            $this->require(
                $base === $this->git->resolveRef($options->workingDirectory),
                'Refresh and check out the current remote base before planning a version PR.',
            );
            $head = $this->ref($repo, $input->managedBranch);
            $pr = $this->pullRequest($repo, $input);
            $hadPr = null !== $pr;
            $this->require(
                null === $pr || $pr['head']['sha'] === $head,
                'The managed PR and branch head snapshots disagree.',
            );
            $plan = $this->planner->plan($options, 'version');
            $this->snapshot($options, $plan, $base);
            $this->require($plan->baseSha === $base, 'The planned base is stale; refresh the base and rerun planning.');
            $evidence = [];
            if (null !== $head && $head !== $base) {
                $owned = $this->policy->inspectHead($options, $head, $input->automationActor, $base);
                if (null !== $pr) {
                    $owned = $owned && $this->botCreatedPullRequest($options, $pr, $input->automationActor);
                }
                $this->require(
                    $owned,
                    'Existing managed head lacks signed Bot ownership or contains unexpected changes; it was preserved.',
                );
                $evidence = $this->evidence($repo, $head);
            } elseif (null !== $pr) {
                $this->require(
                    $this->botCreatedPullRequest($options, $pr, $input->automationActor),
                    'A PR at the base commit must still belong to the configured Bot.',
                );
            }
            if ('none' === $plan->mode()) {
                return $this->result(
                    'none',
                    $plan,
                    $pr,
                    $head,
                    null === $pr ? [] : [
                        'No changes remain; the existing owned PR was preserved for explicit review/closure.',
                    ],
                );
            }
            $data = $this->receipts->decode($plan->receiptContents)->data;
            $this->require(
                ($data['id'] ?? null) === $plan->id && ($data['base_sha'] ?? null) === $base,
                'The generated in-memory plan does not match the planned transaction.',
            );
            $stable = $this->preserveDate($plan, $head, $evidence);
            if ($stable !== $plan) {
                $plan = $stable;
                $data = $this->receipts->decode($plan->receiptContents)->data;
            }
            $title = strtr($input->title, [
                '{current_version}' => $plan->currentVersion,
                '{next_version}' => $plan->nextVersion ?? $plan->currentVersion,
            ]);
            $this->require(
                strlen($title) <= VersionPullRequestInput::MAX_TITLE_BYTES,
                'The resolved version PR title must be at most 256 bytes.',
            );
            $input = $this->inputs->create(
                $input->baseBranch,
                $input->managedBranch,
                $input->automationActor,
                $title,
                $input->dryRun,
            );
            if (($evidence['Plan'] ?? null) === $plan->id) {
                if (null !== $pr && ($pr['title'] ?? null) === $input->title) {
                    return $this->result('unchanged', $plan, $pr, $head);
                }
                if ($input->dryRun) {
                    return $this->result('dry-run', $plan, $pr, $head);
                }
                $this->inputEvidence->validate($plan);
                $this->preflight($options, $input, $base, $head);
                $writing = true;
                $pr = $this->writePullRequest(
                    $repo,
                    $input,
                    $pr,
                    $head,
                    null === $pr ? $this->body($options, $plan, $data) : null,
                );

                return $this->result($hadPr ? 'updated' : 'created', $plan, $pr, $head);
            }
            if ($input->dryRun) {
                return $this->result('dry-run', $plan, $pr, $head);
            }
            $this->inputEvidence->validate($plan);
            $this->preflight($options, $input, $base, $head);
            $baseCommit = $this->github->request('GET', '/repos/' . $repo . '/git/commits/' . $base);
            $treeSha = $baseCommit['tree']['sha'] ?? null;
            $this->require(
                is_string($treeSha) && GitHubEvidence::sha($treeSha),
                'The remote base tree is unavailable.',
            );
            $tree = [
                ['path' => $options->changelogFile, 'mode' => '100644', 'type' => 'blob', 'content' => $plan->changelogContents],
            ];
            foreach ($data['consumed'] as $path => $hash) {
                $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
            }
            $writing = true;
            $createdTree = $this->github->request(
                'POST',
                '/repos/' . $repo . '/git/trees',
                ['base_tree' => $treeSha, 'tree' => $tree],
            );
            $newTree = $createdTree['sha'] ?? null;
            $this->require(
                is_string($newTree) && GitHubEvidence::sha($newTree),
                'GitHub did not confirm the generated tree.',
            );
            if ($newTree === $treeSha) {
                return $this->result(
                    'none',
                    $plan,
                    $pr,
                    $head,
                    ['The generated tree matches the base; no empty PR was created.'],
                );
            }
            $parents = array_values(array_unique([...(null === $head ? [] : [$head]), $base]));
            // GitHub signs App/Bot requests only when custom author, committer and signature are omitted.
            // https://docs.github.com/en/authentication/managing-commit-signature-verification/about-commit-signature-verification#signature-verification-for-bots
            $createdCommit = $this->github->request('POST', '/repos/' . $repo . '/git/commits', [
                'message' => $plan->commitMessage($input->title), 'tree' => $newTree, 'parents' => $parents,
            ]);
            $newHead = $createdCommit['sha'] ?? null;
            $this->require(
                is_string($newHead) && GitHubEvidence::sha($newHead),
                'GitHub did not confirm the generated commit.',
            );
            $this->require(
                $this->policy->inspectHead($options, $newHead, $input->automationActor, $base),
                $this->commitProofDiagnostic($newHead, $createdCommit),
            );
            $this->preflight($options, $input, $base, $head);
            $this->updateRef($repo, $input->managedBranch, $head, $newHead);
            $head = $newHead;
            $this->require(
                $this->ref($repo, $input->baseBranch) === $base && $this->ref($repo, $input->managedBranch) === $head,
                'The base or managed head changed after ref update; inspect the branch before retrying.',
            );
            $pr = $this->writePullRequest($repo, $input, $pr, $head, $this->body($options, $plan, $data));

            return $this->result($hadPr ? 'updated' : 'created', $plan, $pr, $head);
        } catch (VersionPullRequestException $error) {
            return $this->result($writing ? 'conflict' : 'refused', $plan, $pr, $head, [$error->getMessage()]);
        } catch (Throwable) {
            return $this->result(
                $writing ? 'conflict' : 'refused',
                $plan,
                $pr,
                $head,
                [
                    'Version PR synchronization could not be verified; inspect remote state before retrying. Response details were withheld.',
                ],
            );
        }
    }

    /** Confirms the PR creator's canonical Bot ID independently of its refreshable source base. */
    private function botCreatedPullRequest(ReleaseOptions $options, array $pr, string $actor): bool
    {
        $account = $this->github->request('GET', '/users/' . rawurlencode($actor));

        return GitHubEvidence::identity($account, $actor, 'Bot')
            && GitHubEvidence::identity($pr['user'] ?? null, $actor, 'Bot', $account['id']);
    }

    /** Reports only the immutable object identity and allowlisted verification scalars after failed ownership proof. */
    private function commitProofDiagnostic(string $head, array $commit): string
    {
        $verified = match ($commit['verification']['verified'] ?? null) {
            true => 'true', false => 'false', default => 'unavailable',
        };
        $reason = $commit['verification']['reason'] ?? null;
        $known = [
            'valid',
            'unsigned',
            'expired_key',
            'not_signing_key',
            'gpgverify_error',
            'gpgverify_unavailable',
            'unknown_signature_type',
            'no_user',
            'unverified_email',
            'bad_email',
            'unknown_key',
            'malformed_signature',
            'invalid',
        ];

        return 'The generated commit failed signed Bot ownership proof; no branch was updated. generated-sha=' . $head
            . '; create-verification=' . $verified . '; create-reason=' . (in_array(
                $reason,
                $known,
                true,
            ) ? $reason : 'unavailable');
    }

    /** Reads one branch ref, rejecting abbreviated identities and non-commit targets. */
    private function ref(string $repository, string $branch): ?string
    {
        $ref = $this->github->request('GET', '/repos/' . $repository . '/git/ref/heads/' . rawurlencode($branch));
        if (null === $ref) {
            return null;
        }
        $sha = $ref['object']['sha'] ?? null;
        $this->require(
            ($ref['object']['type'] ?? null) === 'commit' && is_string($sha) && GitHubEvidence::sha($sha),
            'A managed/base branch did not resolve to a complete commit identity.',
        );

        return $sha;
    }

    /** Lists every open PR on the exclusive managed branch, refusing a different base or duplicate PR. */
    private function pullRequest(string $repository, VersionPullRequestInput $input): ?array
    {
        $owner = explode('/', $repository)[0];
        $all = $this->github->paginate(
            '/repos/' . $repository . '/pulls?state=open&head=' . rawurlencode($owner . ':' . $input->managedBranch),
        );
        $this->require(count($all) <= 1, 'Multiple open PRs use the exclusive managed branch.');
        if ([] === $all) {
            return null;
        }
        $pr = $all[0];
        $this->require(
            is_int($pr['number'] ?? null) && $pr['number'] > 0 && GitHubEvidence::pullRequest(
                $pr,
                $repository,
                $pr['number'],
            )
            && $pr['head']['ref'] === $input->managedBranch && ($pr['base']['ref'] ?? null) === $input->baseBranch,
            'An unexpected PR or base line uses the exclusive managed branch.',
        );

        return $pr;
    }

    /** Verifies original history bytes and exact fragment scope; the shared input validator proves every fresh consumed base blob. */
    private function snapshot(ReleaseOptions $options, ReleasePlan $plan, string $base): void
    {
        $this->require(
            $this->git->readFileAt(
                $options->workingDirectory,
                $base,
                $options->changelogFile,
            ) === $plan->originalChangelog,
            'Local managed files differ from the fresh committed base; refresh the checkout.',
        );
        if ($plan->resuming && $plan->originalChangelog === $plan->changelogContents) {
            return;
        }
        $data = $this->receipts->decode($plan->receiptContents)->data;
        $consumed = [];
        foreach ($plan->consumed as $absolute => $hash) {
            $relative = $this->paths->relativePath($absolute, $options->workingDirectory);
            if ($plan->resuming) {
                $contents = $this->git->readFileAt($options->workingDirectory, $base, $relative);
                $this->require(
                    null !== $contents && hash('sha256', $contents) === $hash,
                    'A consumed recovery fragment differs from its committed base snapshot.',
                );
            }
            $consumed[$relative] = $hash;
        }
        $this->require(
            $consumed === ($data['consumed'] ?? null),
            'Planned fragment scope differs from the validated in-memory plan.',
        );
    }

    /** Rechecks both branch tips and local HEAD immediately before tree/ref mutation. */
    private function preflight(
        ReleaseOptions $options,
        VersionPullRequestInput $input,
        string $base,
        ?string $head,
    ): void {
        $this->require(
            $this->ref($options->repository, $input->baseBranch) === $base
            && $this->ref($options->repository, $input->managedBranch) === $head
            && $this->git->resolveRef($options->workingDirectory) === $base,
            'The base or managed head changed; refresh and replan without overwriting it.',
        );
    }

    /**
     * Reads unique scalar trailers after immutable-head ownership and scope validation.
     * Missing, malformed or duplicate fields cannot authorize reuse of a prior date.
     *
     * @return array<string,string>
     */
    private function evidence(string $repository, string $head): array
    {
        $commit = $this->github->request('GET', '/repos/' . $repository . '/commits/' . $head);
        $message = $commit['commit']['message'] ?? '';
        $evidence = [];
        foreach (['Plan', 'Base', 'Output', 'Options'] as $name) {
            $hash = 'Base' === $name ? '(?:[a-f0-9]{40}|[a-f0-9]{64})' : '[a-f0-9]{64}';
            if (is_string($message)
                && 1 === preg_match_all('/^Changelog-' . $name . ':.*$/m', $message)
                && 1 === preg_match_all(
                    '/^Changelog-' . $name . ': (' . $hash . ')$/m',
                    $message,
                    $matches,
                )) {
                $evidence[$name] = $matches[1][0];
            }
        }

        return $evidence;
    }

    /**
     * Reuses an owned release date only when fresh inputs reconstruct the entire signed transaction.
     * The clock remains part of ordinary planning; only a candidate with identical base, options,
     * canonical receipt ID and complete output bytes can replace the fresh plan. Recovery and
     * maintenance retain their existing semantics. No old template or executable PR content is loaded.
     *
     * @param array<string,string> $evidence unique trailers from the owned immutable head
     */
    private function preserveDate(ReleasePlan $plan, ?string $head, array $evidence): ReleasePlan
    {
        if (null === $head || null === $plan->nextVersion || $plan->resuming
            || ($evidence['Plan'] ?? null) === $plan->id
            || ($evidence['Base'] ?? null) !== $plan->baseSha
            || ($evidence['Options'] ?? null) !== $plan->options->evidenceHash()
            || ! isset($evidence['Plan'], $evidence['Output'])) {
            return $plan;
        }
        $contents = GitHubEvidence::content(
            $this->github->request('GET', GitHubEvidence::contentsPath(
                $plan->options->repository,
                $plan->options->changelogFile,
                $head,
            )),
        );
        $this->require(null !== $contents, 'The existing managed changelog could not be read at its verified head.');
        if (hash('sha256', $contents) !== $evidence['Output']) {
            return $plan;
        }
        $this->inputEvidence->validateTemplate($plan->options, $plan->baseSha);
        $template = $this->templates->resolve($plan->options);
        $previous = $this->history->parse($contents, $template)->getRelease($plan->nextVersion);
        if (null === $previous || null === $previous->getDate()) {
            return $plan;
        }
        $document = $this->history->parse($plan->changelogContents, $template);
        $releases = [];
        foreach ($document->getReleases() as $release) {
            $releases[] = $release->getVersion() === $plan->nextVersion
                ? $this->releases->create(
                    $release->getVersion(),
                    $previous->getDate(),
                    'release-plan',
                    $release->getBody(),
                    null,
                    $release->getEnding(),
                ) : $release;
        }
        $candidateContents = $this->history->render($document->withReleases($releases), $template, true);
        $candidate = $this->plans->create(
            $plan->options,
            $plan->baseSha,
            $plan->currentVersion,
            $plan->nextVersion,
            $plan->impact,
            $plan->consumed,
            $plan->historicalVersions,
            $plan->changelogPath,
            $plan->originalChangelog,
            $candidateContents,
            $this->history->notes($this->history->parse($candidateContents, $template), $plan->nextVersion),
            $plan->receiptPath,
            $plan->originalReceipt,
        );

        return $candidate->id === $evidence['Plan'] && $candidate->changelogContents === $contents ? $candidate : $plan;
    }

    /** Uses a non-forced descendant ref update; after a lost response only an exact GET confirmation counts. */
    private function updateRef(string $repository, string $branch, ?string $old, string $head): void
    {
        try {
            if (null === $old) {
                $this->github->request(
                    'POST',
                    '/repos/' . $repository . '/git/refs',
                    ['ref' => 'refs/heads/' . $branch, 'sha' => $head],
                );
            } else {
                $this->github->request(
                    'PATCH',
                    '/repos/' . $repository . '/git/refs/heads/' . rawurlencode($branch),
                    ['sha' => $head, 'force' => false],
                );
            }
        } catch (Throwable) {
            $this->require(
                $this->ref($repository, $branch) === $head,
                'Ref update outcome was not confirmed; existing remote history was preserved.',
            );

            return;
        }
        $this->require($this->ref($repository, $branch) === $head, 'GitHub did not confirm the intended managed head.');
    }

    /**
     * Creates or refreshes the one PR without retrying a mutation. Existing PR writes are confirmed
     * through their trusted number because a successful PATCH may still return the previous head;
     * an uncertain create is recovered by its exclusive branch query. Unconfirmed identities or titles fail closed.
     * A null body preserves existing human PR text during a title-only refresh; new PRs receive generated text.
     */
    private function writePullRequest(
        string $repository,
        VersionPullRequestInput $input,
        ?array $pr,
        string $head,
        ?string $body,
    ): array {
        try {
            $response = null === $pr
                ? $this->github->request(
                    'POST',
                    '/repos/' . $repository . '/pulls',
                    ['title' => $input->title, 'body' => $body, 'head' => $input->managedBranch, 'base' => $input->baseBranch],
                )
                : $this->github->request(
                    'PATCH',
                    '/repos/' . $repository . '/pulls/' . $pr['number'],
                    ['title' => $input->title] + (null === $body ? [] : ['body' => $body]),
                );
        } catch (Throwable) {
            $response = null === $pr ? $this->pullRequest($repository, $input) : null;
        }
        if (null !== $pr) {
            $response = $this->github->request('GET', '/repos/' . $repository . '/pulls/' . $pr['number']);
        }
        $this->require(
            null !== $response && GitHubEvidence::pullRequest($response, $repository, $response['number'] ?? 0)
            && (null === $pr || $response['number'] === $pr['number'])
            && $response['head']['sha'] === $head && $response['head']['ref'] === $input->managedBranch
            && ($response['base']['ref'] ?? null) === $input->baseBranch
            && ($response['title'] ?? null) === $input->title
            && GitHubEvidence::identity(
                $response['user'] ?? null,
                $input->automationActor,
                'Bot',
                $pr['user']['id'] ?? null,
            )
            && is_string($response['html_url'] ?? null) && str_starts_with($response['html_url'], 'https://'),
            'The version PR write was not confirmed as an owned PR at the intended head.',
        );

        return $response;
    }

    /** Throws only factory-created controlled diagnostics, keeping unknown collaborator messages redacted. */
    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw $this->exceptions->create($message);
        }
    }

    /** Produces editable explanatory PR text; ownership is proved independently of these fields. */
    private function body(ReleaseOptions $options, ReleasePlan $plan, array $data): string
    {
        return ('maintenance' === $plan->mode() ? 'Maintain existing changelog history.' : 'Prepare release ' . $plan->nextVersion . '.')
            . "\n\nPlan: `" . $plan->id . "`\nBase: `" . $plan->baseSha . "`\nConsumed fragments: " . count(
                $data['consumed'],
            )
            . "\n\nUpdates the changelog and removes the consumed fragments."
            . "\nPreparing this PR does not publish a tag or release."
            . "\nPublication after merge depends on the consumer workflow.\n";
    }

    /** Captures the exact plan and known remote PR/head state without claiming publication. */
    private function result(
        string $status,
        ?ReleasePlan $plan,
        ?array $pr,
        ?string $head,
        array $diagnostics = [],
    ): VersionPullRequestResult {
        return $this->results->create(
            $status,
            $pr['number'] ?? null,
            $pr['html_url'] ?? null,
            $head,
            $plan?->id,
            $plan?->nextVersion,
            null !== $plan && 'maintenance' === $plan->mode(),
            $diagnostics,
        );
    }
}
