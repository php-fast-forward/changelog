<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Publication;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\GitHub\GitHubRetryPolicy;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Validator\MergedPublicationEvidenceValidatorInterface;
use FastForward\Changelog\Validator\PublicationEvidenceValidatorInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Reconciles create-only GitHub publication after independent commit/domain evidence proof. */
final readonly class PublicationService implements MergedPublicationServiceInterface
{
    /**
     * Injects proof, authenticated HTTP, typed output and failures without performing any I/O.
     *
     * @param PublicationEvidenceValidatorInterface $evidence Independent validator of approved committed release
     *                                                        semantics and exact note bytes.
     * @param GitHubClientInterface                 $github   Authenticated, replaceable GitHub API boundary for scoped reads and writes.
     * @param ?PullRequestPolicyInterface           $policy   Optional signed-head ownership verifier; merged-PR publication is
     *                                                        refused when this capability is absent.
     * @param ?GitHubRetryPolicy                    $retries  Optional bounded retry policy, consulted only after authoritative create-only reconciliation.
     */
    public function __construct(
        private PublicationEvidenceValidatorInterface $evidence,
        private GitHubClientInterface $github,
        private ?PullRequestPolicyInterface $policy = null,
        private ?GitHubRetryPolicy $retries = null,
    ) {}

    /**
     * Creates only missing remote objects and verifies every uncertain response by a fresh read.
     *
     * @param  ReleaseOptions           $options     Trusted consumer paths, rendering settings and repository selection for this
     *                                               operation.
     * @param  string                   $approvedSha Complete approved commit identity whose exact committed transaction is required.
     * @param  bool                     $dryRun      Whether to return the proposed transaction without creating or updating remote content.
     * @return PublicationResult        Verified maintenance, dry-run, published or unchanged result for the approved commit.
     * @throws InvalidArgumentException When complete approved transaction or contextual merge evidence is absent or
     *                                  inconsistent.
     * @throws RuntimeException         When immutable API authority or matching create-only publication state cannot be
     *                                  confirmed.
     */
    public function publish(
        ReleaseOptions $options,
        string $approvedSha,
        bool $dryRun = false,
    ): PublicationResult {
        return $this->write($this->evidence->validate($options, $approvedSha), $dryRun);
    }

    /**
     * Binds immutable PR authority to its signed head before validating the complete merge domain or writing.
     *
     * @param  ReleaseOptions           $options         Trusted consumer paths, rendering settings and repository selection for this
     *                                                   operation.
     * @param  int                      $pullRequest     Positive pull-request number associated with the approved automation snapshot.
     * @param  string                   $mergeSha        Exact approved merge commit that will be validated and, if applicable, tagged.
     * @param  string                   $headSha         Immutable generated PR head whose ownership and committed output must be proved.
     * @param  string                   $baseBranch      Trusted release-line branch that supplies the fresh source base.
     * @param  string                   $managedBranch   Exclusive automation branch reserved for the generated version PR.
     * @param  string                   $automationActor Canonical GitHub Bot login required to own and sign generated commits.
     * @param  bool                     $dryRun          Whether to return the proposed transaction without creating or updating remote content.
     * @return PublicationResult        Publication result for the exact verified merge; no tag or release is moved or
     *                                  overwritten.
     * @throws InvalidArgumentException When complete approved transaction or contextual merge evidence is absent or
     *                                  inconsistent.
     * @throws RuntimeException         When immutable API authority or matching create-only publication state cannot be
     *                                  confirmed.
     */
    public function publishMerged(
        ReleaseOptions $options,
        int $pullRequest,
        string $mergeSha,
        string $headSha,
        string $baseBranch = 'main',
        string $managedBranch = 'changelog/version',
        string $automationActor = 'github-actions[bot]',
        bool $dryRun = false,
    ): PublicationResult {
        if (!GitHubEvidence::repository($options->repository) || $pullRequest < 1
            || !GitHubEvidence::sha($mergeSha) || !GitHubEvidence::sha($headSha)
            || '' === $baseBranch || '' === $managedBranch || '' === $automationActor
            || null === $this->policy || !$this->evidence instanceof MergedPublicationEvidenceValidatorInterface) {
            throw new InvalidArgumentException(
                'Merged publication requires complete PR identities and contextual proof capabilities.',
            );
        }
        $pr = $this->github->request('GET', '/repos/' . $options->repository . '/pulls/' . $pullRequest);

        if (null === $pr || ($pr['number'] ?? null) !== $pullRequest
            || ($pr['state'] ?? null) !== 'closed' || true !== ($pr['merged'] ?? null)
            || (array_key_exists('merge_commit_sha', $pr) && $pr['merge_commit_sha'] !== $mergeSha)
            || ($pr['head']['sha'] ?? null) !== $headSha
            || ($pr['head']['repo']['full_name'] ?? null) !== $options->repository
            || ($pr['base']['repo']['full_name'] ?? null) !== $options->repository
            || !is_int($pr['base']['repo']['id'] ?? null) || $pr['base']['repo']['id'] < 1
            || ($pr['head']['repo']['id'] ?? null) !== $pr['base']['repo']['id']
            || !is_string($pr['base']['repo']['node_id'] ?? null) || '' === $pr['base']['repo']['node_id']
            || ($pr['head']['repo']['node_id'] ?? null) !== $pr['base']['repo']['node_id']
            || ($pr['head']['ref'] ?? null) !== $managedBranch || ($pr['base']['ref'] ?? null) !== $baseBranch
            || !GitHubEvidence::identity($pr['user'] ?? null, $automationActor, 'Bot')) {
            throw new RuntimeException(
                'Publication requires the merged configured version PR and its immutable head/merge identities.',
            );
        }
        $this->assertMergeIdentity(
            $options->repository,
            $pullRequest,
            $mergeSha,
            $headSha,
            $pr['base']['repo']['node_id'],
            $baseBranch,
            $managedBranch,
        );
        $account = $this->github->request('GET', '/users/' . rawurlencode($automationActor));

        if (!GitHubEvidence::identity($account, $automationActor, 'Bot', $pr['user']['id'])
            || !$this->policy->inspectHead($options, $headSha, $automationActor, $mergeSha)) {
            throw new RuntimeException('The merged version PR lacks signed automation ownership.');
        }

        return $this->write($this->evidence->validateMerged($options, $mergeSha, $headSha), $dryRun);
    }

    /**
     * Corroborates the complete REST relationship with GraphQL's exact merged commit before any write.
     *
     * REST 2026 removes merge_commit_sha; absent/error/partial GraphQL evidence never becomes authority.
     * Opaque repository Node IDs must match REST exactly, without decoding or deriving numeric identities.
     *
     * @param  string $repository       Explicit GitHub owner/name used to construct repository-scoped API endpoints.
     * @param  int    $pullRequest      Positive pull-request number associated with the approved automation snapshot.
     * @param  string $mergeSha         Exact approved merge commit that will be validated and, if applicable, tagged.
     * @param  string $headSha          Immutable generated PR head whose ownership and committed output must be proved.
     * @param  string $repositoryNodeId Opaque REST repository node_id that GraphQL id must match byte for byte.
     * @param  string $baseBranch       Trusted release-line branch that supplies the fresh source base.
     * @param  string $managedBranch    Exclusive automation branch reserved for the generated version PR.
     * @return void   No value; the complete GraphQL merge relationship must corroborate the REST evidence.
     * @see https://docs.github.com/en/rest/about-the-rest-api/breaking-changes?apiVersion=2026-03-10
     * @see https://docs.github.com/en/enterprise-server@3.19/graphql/guides/using-global-node-ids
     * @throws RuntimeException When immutable API authority or matching create-only publication state cannot be
     *                          confirmed.
     */
    private function assertMergeIdentity(
        string $repository,
        int $pullRequest,
        string $mergeSha,
        string $headSha,
        string $repositoryNodeId,
        string $baseBranch,
        string $managedBranch,
    ): void {
        [$owner, $name] = explode('/', $repository, 2);
        $proof = $this->github->request('POST', '/graphql', [
            'query' => <<<'GRAPHQL'
query ChangelogMergedPullRequest($owner: String!, $name: String!, $number: Int!) {
  repository(owner: $owner, name: $name) {
    nameWithOwner
    id
    pullRequest(number: $number) {
      number
      state
      merged
      headRefOid
      headRefName
      baseRefName
      mergeCommit { oid }
    }
  }
}
GRAPHQL,
            'variables' => ['owner' => $owner, 'name' => $name, 'number' => $pullRequest],
        ]);
        $project = $proof['data']['repository'] ?? null;
        $pr = is_array($project) ? ($project['pullRequest'] ?? null) : null;

        if (null === $proof || (array_key_exists('errors', $proof) && [] !== $proof['errors'])
            || ! is_array($project) || ! is_array($pr)
            || ($project['nameWithOwner'] ?? null) !== $repository
            || ($project['id'] ?? null) !== $repositoryNodeId
            || ($pr['number'] ?? null) !== $pullRequest || ($pr['state'] ?? null) !== 'MERGED'
            || true !== ($pr['merged'] ?? null) || ($pr['headRefOid'] ?? null) !== $headSha
            || ($pr['headRefName'] ?? null) !== $managedBranch || ($pr['baseRefName'] ?? null) !== $baseBranch
            || ($pr['mergeCommit']['oid'] ?? null) !== $mergeSha) {
            throw new RuntimeException(
                'GraphQL must corroborate the exact merged PR, repository, branches, head and merge commit.',
            );
        }
    }

    /**
     * Shares create-only reconciliation only after one of the complete public proof paths succeeds.
     *
     * @param  PublicationEvidence $evidence Independently validated transaction evidence required before publication or
     *                                       deterministic reuse.
     * @param  bool                $dryRun   Whether to return the proposed transaction without creating or updating remote content.
     * @return PublicationResult   Create-only publication outcome, retaining an existing matching tag or release on
     *                             retries.
     * @throws RuntimeException    When immutable API authority or matching create-only publication state cannot be
     *                             confirmed.
     */
    private function write(PublicationEvidence $evidence, bool $dryRun): PublicationResult
    {
        if (null === $evidence->version) {
            return new PublicationResult('maintenance', null, null, $evidence->sha, null, []);
        }
        $root = '/repos/' . $evidence->repository;
        $tag = $evidence->tag;
        $sha = $this->tagSha($root, $tag);
        $release = $this->github->request('GET', $root . '/releases/tags/' . rawurlencode($tag));

        if (null !== $sha && $sha !== $evidence->sha) {
            throw new RuntimeException('The release tag already points to another commit; it will never be moved.');
        }

        if (null !== $release) {
            $this->releaseUrl($release, $tag, $evidence->notes);

            if (null === $sha) {
                throw new RuntimeException('An existing release has no verified matching tag; publication refused.');
            }
        }
        $actions = [];

        if (null === $sha) {
            $actions[] = 'create_tag';
        }

        if (null === $release) {
            $actions[] = 'create_release';
        }

        if ($dryRun) {
            return new PublicationResult(
                state: 'dry-run',
                version: $evidence->version,
                tag: $tag,
                sha: $evidence->sha,
                url: null === $release ? null : $this->releaseUrl($release, $tag, $evidence->notes),
                actions: $actions,
            );
        }

        if (null === $sha) {
            $this->createTag($evidence, $root, $tag);
        }

        $release ??= $this->createRelease($evidence, $root, $tag);
        $url = $this->releaseUrl($release, $tag, $evidence->notes);

        if ($this->tagSha($root, $tag) !== $evidence->sha) {
            throw new RuntimeException(
                'The release tag changed during publication; no tag rewrite or release update was attempted.',
            );
        }

        return new PublicationResult(
            state: [] === $actions ? 'unchanged' : 'published',
            version: $evidence->version,
            tag: $tag,
            sha: $evidence->sha,
            url: $url,
            actions: $actions,
        );
    }

    /**
     * Reconciles a missing tag after each uncertain response before any bounded transient retry.
     *
     * @param  PublicationEvidence $evidence Independently validated transaction evidence required before publication or
     *                                       deterministic reuse.
     * @param  string              $root     Trusted /repos/owner/name API prefix, never an external request URL.
     * @param  string              $tag      Exact release tag name selected by the transaction.
     * @return void                No value; a fresh read must confirm the missing tag now targets the exact approved commit.
     * @throws RuntimeException    When immutable API authority or matching create-only publication state cannot be
     *                             confirmed.
     */
    private function createTag(PublicationEvidence $evidence, string $root, string $tag): void
    {
        for ($attempt = 1; ; ++$attempt) {
            if ($attempt > 1) {
                $sha = $this->tagSha($root, $tag);

                if ($sha === $evidence->sha) {
                    return;
                }

                if (null !== $sha) {
                    throw new RuntimeException('The release tag changed before retry; publication refused.');
                }
            }
            $error = $this->create($root . '/git/refs', ['ref' => 'refs/tags/' . $tag, 'sha' => $evidence->sha]);
            $sha = $this->tagSha($root, $tag);

            if ($sha === $evidence->sha) {
                return;
            }

            if (null !== $sha || null === $this->retries || ! $this->retries->retry($error, $attempt)) {
                throw new RuntimeException(
                    'Tag creation outcome is missing or conflicts with the approved commit; retry after checking remote state.',
                    previous: $error,
                );
            }
        }
    }

    /**
     * Rechecks the tag and exact release state before each bounded transient create retry.
     *
     * @param  PublicationEvidence $evidence Independently validated transaction evidence required before publication or
     *                                       deterministic reuse.
     * @param  string              $root     Trusted /repos/owner/name API prefix, never an external request URL.
     * @param  string              $tag      Exact release tag name selected by the transaction.
     * @return array               Persisted release response obtained by GET, with exact notes and stable publication state.
     * @throws RuntimeException    When immutable API authority or matching create-only publication state cannot be
     *                             confirmed.
     */
    private function createRelease(PublicationEvidence $evidence, string $root, string $tag): array
    {
        for ($attempt = 1; ; ++$attempt) {
            if ($this->tagSha($root, $tag) !== $evidence->sha) {
                throw new RuntimeException('The verified tag changed before release creation; publication refused.');
            }

            if ($attempt > 1) {
                $release = $this->github->request('GET', $root . '/releases/tags/' . rawurlencode($tag));

                if (null !== $release) {
                    $this->releaseUrl($release, $tag, $evidence->notes);

                    return $release;
                }
            }
            $error = $this->create($root . '/releases', ['tag_name' => $tag, 'target_commitish' => $evidence->sha,
                'name' => $tag, 'body' => $evidence->notes, 'draft' => false, 'prerelease' => false, 'generate_release_notes' => false]);
            $release = $this->github->request('GET', $root . '/releases/tags/' . rawurlencode($tag));

            if (null !== $release) {
                $this->releaseUrl($release, $tag, $evidence->notes);

                return $release;
            }

            if (null === $this->retries || ! $this->retries->retry($error, $attempt)) {
                throw new RuntimeException(
                    'The matching tag is retained but release creation is unverified; retry the same approved SHA.',
                    previous: $error,
                );
            }
        }
    }

    /**
     * Treats a failed create response as uncertain; callers MUST verify the corresponding persisted object.
     *
     * @param  string              $path Trusted repository-scoped create-only API endpoint, never an external URL.
     * @param  array<string,mixed> $body Exact create-only tag or release JSON payload; the request is never
     *                                   replayed by this low-level helper; owners reconcile state before any retry.
     * @return ?Throwable          Captured creation failure, or null after a successful response; neither result establishes
     *                             persistence.
     */
    private function create(string $path, array $body): ?Throwable
    {
        try {
            $this->github->request('POST', $path, $body);

            return null;
        } catch (Throwable $error) {
            // A lost response or concurrent creation is reconciled through authoritative GET evidence.
            return $error;
        }
    }

    /**
     * Dereferences exact lightweight/annotated tag objects through bounded trusted relative endpoints.
     *
     * @param  string           $root Trusted /repos/owner/name API prefix, never an external request URL.
     * @param  string           $tag  Exact release tag name selected by the transaction.
     * @return ?string          Dereferenced complete commit identity, or null when the exact tag reference is absent.
     * @throws RuntimeException When immutable API authority or matching create-only publication state cannot be
     *                          confirmed.
     */
    private function tagSha(string $root, string $tag): ?string
    {
        $reference = $this->github->request('GET', $root . '/git/ref/tags/' . rawurlencode($tag));

        if (null === $reference) {
            return null;
        }

        if (($reference['ref'] ?? null) !== 'refs/tags/' . $tag || ! is_array($reference['object'] ?? null)) {
            throw new RuntimeException('GitHub returned an invalid exact tag reference.');
        }
        $object = $reference['object'];
        $seen = [];

        for ($depth = 0; ; ++$depth) {
            $sha = $object['sha'] ?? null;
            $type = $object['type'] ?? null;

            if (! is_string($sha) || 1 !== preg_match('~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D', $sha)) {
                throw new RuntimeException('GitHub returned an invalid tag object identity.');
            }

            if ('commit' === $type) {
                return $sha;
            }

            if ('tag' !== $type || isset($seen[$sha]) || $depth >= 8) {
                throw new RuntimeException(
                    'GitHub tag objects must terminate in a commit without cycles or excessive nesting.',
                );
            }
            $seen[$sha] = true;
            $annotated = $this->github->request('GET', $root . '/git/tags/' . $sha);

            if (null === $annotated || ($annotated['sha'] ?? null) !== $sha || ! is_array(
                $annotated['object'] ?? null,
            )) {
                throw new RuntimeException('GitHub returned an invalid annotated tag object.');
            }
            $object = $annotated['object'];
        }
    }

    /**
     * Requires exact central notes, stable published state and a usable HTTPS release URL.
     *
     * @param  array<string,mixed> $release Persisted GitHub Release response to compare with the exact approved tag,
     *                                      notes and state.
     * @param  string              $tag     Exact release tag name selected by the transaction.
     * @param  string              $notes   Exact approved release-note bytes; presentation or newline changes are significant.
     * @return string              Confirmed HTTPS release URL only when tag, note bytes and published state match.
     * @throws RuntimeException    When immutable API authority or matching create-only publication state cannot be
     *                             confirmed.
     */
    private function releaseUrl(array $release, string $tag, string $notes): string
    {
        $url = $release['html_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;

        if (($release['tag_name'] ?? null) !== $tag || ($release['body'] ?? null) !== $notes
            || false !== ($release['draft'] ?? null) || false !== ($release['prerelease'] ?? null)
            || ! is_array($parts) || 'https' !== ($parts['scheme'] ?? null) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || 1 === preg_match(
                '~[\x00-\x20\x7f]~',
                $url,
            )) {
            throw new RuntimeException(
                'The existing release differs from the exact approved tag, notes or published state.',
            );
        }

        return $url;
    }
}
