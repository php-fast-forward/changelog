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

namespace FastForward\Changelog\Release\Reference;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitFragmentOriginReaderInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Corroborates optional PR presentation through exact source blobs, Git ancestry and two GitHub APIs. */
final readonly class PullRequestReferenceResolver implements PullRequestReferenceResolverInterface
{
    /**
     * Injects boundaries without consulting Git, the filesystem, credentials or the network.
     *
     * @param GitRepositoryInterface       $git    Immutable blobs and ancestry; origin discovery is an optional additional capability.
     * @param GitHubClientInterface        $github Scoped REST/GraphQL transport with complete, bounded pagination.
     * @param PackagePathResolverInterface $paths  Pure mapping between consumer paths and the Git worktree root.
     */
    public function __construct(
        private GitRepositoryInterface $git,
        private GitHubClientInterface $github,
        private PackagePathResolverInterface $paths,
    ) {}

    /**
     * Preserves explicit references and enriches only uniquely proved committed fragments.
     *
     * All caches belong to this invocation. Operational failures are redacted and never
     * downgraded to an empty association list; no fragment or remote object is modified.
     *
     * @param  ReleaseOptions           $options        Trusted consumer paths, explicit repository and opt-in resolution policy.
     * @param  ?string                  $baseSha        Immutable source base, or null when no committed origin is available.
     * @param  list<Changeset>          $changesets     Validated fragments whose existing PR, issue, author and Markdown are retained.
     * @param  array<string,string>     $acceptedHashes Accepted source inventory keyed by absolute fragment path; empty means records reconstructed from the base commit.
     * @return list<Changeset>          Original array when no enrichment applies; otherwise records differing only in absent PR numbers.
     * @throws InvalidArgumentException When enabled resolution receives an invalid repository or immutable base.
     * @throws RuntimeException         When proof is unavailable, incomplete, contradictory or identifies multiple valid PR numbers.
     */
    public function resolve(
        ReleaseOptions $options,
        ?string $baseSha,
        array $changesets,
        array $acceptedHashes = [],
    ): array {
        if (!$options->resolvePullRequests || null === $options->repository || null === $baseSha
            || [] === array_filter($changesets, static fn(Changeset $change): bool => null === $change->pullRequest)) {
            return $changesets;
        }

        if (!GitHubEvidence::repository($options->repository) || !GitHubEvidence::sha($baseSha)) {
            throw new InvalidArgumentException(
                'Automatic PR references require a repository and complete source-base SHA.',
            );
        }

        if (!$this->git instanceof GitFragmentOriginReaderInterface) {
            throw new RuntimeException('Automatic PR references require the Git fragment-origin capability.');
        }

        try {
            $references = $this->references($options, $baseSha, $changesets, $acceptedHashes);
        } catch (Throwable) {
            throw new RuntimeException(
                'Automatic pull-request reference evidence could not be verified; details were withheld.',
            );
        }

        foreach ($references as $index => $numbers) {
            if (count($numbers) > 1) {
                throw new RuntimeException(
                    'Automatic pull-request reference is ambiguous: multiple merged PRs prove the same fragment.',
                );
            }

            if ([] === $numbers) {
                continue;
            }
            $change = $changesets[$index];
            $changesets[$index] = new Changeset(
                $change->id, $change->category, $change->issue, $numbers[0],
                $change->author, $change->description, $change->type,
            );
        }

        return $changesets;
    }

    /**
     * Collects complete proofs with per-operation origin and PR identity caches.
     *
     * @param  ReleaseOptions       $options        Trusted consumer root, fragment directory and GitHub repository.
     * @param  string               $baseSha        Complete immutable commit from which fragment blobs are selected.
     * @param  list<Changeset>      $changesets     Validated records; explicit references require no origin lookup.
     * @param  array<string,string> $acceptedHashes Accepted source inventory, or an empty map for committed reconstruction.
     * @return array<int,list<int>> Unique proved PR numbers indexed by the original fragment position.
     * @throws RuntimeException     When any selected Git or GitHub evidence cannot be corroborated.
     */
    private function references(
        ReleaseOptions $options,
        string $baseSha,
        array $changesets,
        array $acceptedHashes,
    ): array {
        $directory = $this->git->repositoryRoot($options->workingDirectory);
        $origins = $this->origins($options, $directory, $baseSha, $changesets, $acceptedHashes);

        if ([] === $origins) {
            return [];
        }
        $repository = $this->repository($options->repository);
        $associations = [];
        $merges = [];
        $references = [];

        foreach ($origins as $index => [$path, $blob, $origin]) {
            $associations[$origin] ??= $this->associatedNumbers($options->repository, $origin);
            $references[$index] = [];

            foreach ($associations[$origin] as $number) {
                if (!array_key_exists($number, $merges)) {
                    $merges[$number] = $this->mergedCommit($options->repository, $repository, $number);
                }
                $merge = $merges[$number];

                if (null !== $merge && $this->git->isAncestor($directory, $origin, $merge)
                    && $this->git->isAncestor($directory, $merge, $baseSha)
                    && $this->git->readFileAt($directory, $merge, $path) === $blob) {
                    $references[$index][] = $number;
                }
            }
        }

        return $references;
    }

    /**
     * Pins each eligible fragment to exact source bytes before asking the Git origin capability.
     *
     * @param  ReleaseOptions                         $options        Trusted consumer root and canonical fragment directory.
     * @param  string                                 $directory      Canonical Git root used consistently for all relative Git operations.
     * @param  string                                 $baseSha        Complete source commit whose blob supplies the accepted SHA-256.
     * @param  list<Changeset>                        $changesets     Validated fragments; uncommitted and explicit-PR records are omitted.
     * @param  array<string,string>                   $acceptedHashes Absolute source paths mapped to accepted SHA-256 values, or empty for committed records.
     * @return array<int,array{string,string,string}> Repository-relative path, exact blob and introducing commit per eligible fragment.
     * @throws RuntimeException                       When path normalization escapes the Git root or origin evidence is malformed.
     */
    private function origins(
        ReleaseOptions $options,
        string $directory,
        string $baseSha,
        array $changesets,
        array $acceptedHashes,
    ): array {
        $origins = [];

        foreach ($changesets as $index => $change) {
            if (null !== $change->pullRequest) {
                continue;
            }
            $absolute = $this->paths->absolutePath(
                $options->fragmentDirectory . '/' . $change->id,
                $options->workingDirectory,
            );
            $path = $this->paths->relativePath($absolute, $directory);

            if (1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, $change->id)
                || $this->paths->isAbsolute($path) || '' === $path
                || str_contains($path, '\\') || str_contains($path, "\0")
                || [] !== array_intersect(['', '.', '..'], explode('/', $path))) {
                throw new RuntimeException('A fragment origin requires a canonical path within the Git root.');
            }
            $accepted = $acceptedHashes[$absolute] ?? null;

            if ([] !== $acceptedHashes && (!is_string($accepted) || 1 !== preg_match(
                '/\A[a-f0-9]{64}\z/',
                $accepted,
            ))) {
                throw new RuntimeException(
                    'An accepted fragment inventory requires a complete SHA-256 for every eligible path.',
                );
            }
            $blob = $this->git->readFileAt($directory, $baseSha, $path);

            if (null === $blob) {
                continue;
            }
            $hash = hash('sha256', $blob);

            if ([] !== $acceptedHashes && !hash_equals($accepted, $hash)) {
                continue;
            }
            $origin = $this->git->fragmentOrigin($directory, $baseSha, $path, $hash);

            if (null === $origin) {
                continue;
            }

            if (!GitHubEvidence::sha($origin)) {
                throw new RuntimeException('A fragment origin must be a complete immutable commit SHA.');
            }
            $origins[$index] = [$path, $blob, $origin];
        }

        return $origins;
    }

    /**
     * Establishes repository authority without deriving opaque Node IDs from numeric database IDs.
     *
     * @param  string                                        $name Explicit owner/name selected by trusted release options.
     * @return array{id:int,node_id:string,full_name:string} Positive REST identity and canonical name bound to GraphQL.
     * @throws RuntimeException                              When repository evidence is absent, malformed or names a different repository.
     */
    private function repository(string $name): array
    {
        $repository = $this->github->request('GET', '/repos/' . $name);

        if (null === $repository || !is_int($repository['id'] ?? null) || $repository['id'] < 1
            || !is_string($repository['node_id'] ?? null) || '' === $repository['node_id']
            || !is_string($repository['full_name'] ?? null) || 0 !== strcasecmp($repository['full_name'], $name)) {
            throw new RuntimeException('Automatic PR references require verified repository identity.');
        }

        return ['id' => $repository['id'], 'node_id' => $repository['node_id'], 'full_name' => $repository['full_name']];
    }

    /**
     * Deduplicates complete paginated association results without treating API failure as no PR.
     *
     * @param  string           $repository Trusted repository owner/name used only in scoped relative API paths.
     * @param  string           $origin     Complete introducing commit established from the selected source blob.
     * @return list<int>        Distinct positive associated PR numbers; an authoritative empty list remains empty.
     * @throws RuntimeException When a returned association lacks a positive integer PR identity.
     */
    private function associatedNumbers(string $repository, string $origin): array
    {
        $numbers = [];

        foreach ($this->github->paginate('/repos/' . $repository . '/commits/' . $origin . '/pulls') as $pr) {
            $number = $pr['number'] ?? null;

            if (!is_int($number) || $number < 1) {
                throw new RuntimeException('A commit association must identify a positive pull-request number.');
            }
            $numbers[$number] = $number;
        }

        return array_values($numbers);
    }

    /**
     * Confirms a merged PR against repository REST identity and GraphQL merge authority.
     *
     * Fork heads are allowed: only the target repository is bound to the selected repository.
     * Open and closed-unmerged PRs supply no reference; incomplete evidence never does so.
     *
     * @param  string                                        $name       Trusted owner/name used for the repository-scoped PR request.
     * @param  array{id:int,node_id:string,full_name:string} $repository Verified target repository identity.
     * @param  int                                           $number     Positive association number corroborated by the full PR response.
     * @return ?string                                       Complete merged commit SHA, or null for a valid unmerged PR.
     * @throws RuntimeException                              When REST or GraphQL evidence is absent, inconsistent or incomplete.
     */
    private function mergedCommit(string $name, array $repository, int $number): ?string
    {
        $pr = $this->github->request('GET', '/repos/' . $name . '/pulls/' . $number);

        if (null === $pr || ($pr['number'] ?? null) !== $number
            || !in_array($pr['state'] ?? null, ['open', 'closed'], true) || !is_bool($pr['merged'] ?? null)
            || ($pr['base']['repo']['id'] ?? null) !== $repository['id']
            || ($pr['base']['repo']['node_id'] ?? null) !== $repository['node_id']
            || ($pr['base']['repo']['full_name'] ?? null) !== $repository['full_name']) {
            throw new RuntimeException('A pull request must corroborate its target repository and merged state.');
        }

        if (!$pr['merged']) {
            return null;
        }

        if ('closed' !== $pr['state'] || !is_string($pr['head']['sha'] ?? null)
            || !GitHubEvidence::sha($pr['head']['sha']) || !is_string($pr['head']['ref'] ?? null)
            || '' === $pr['head']['ref'] || !is_string($pr['base']['ref'] ?? null) || '' === $pr['base']['ref']) {
            throw new RuntimeException('A merged pull request requires complete head and branch identities.');
        }
        $merge = $this->graphMerge($repository, $number, $pr);

        if (array_key_exists('merge_commit_sha', $pr) && $pr['merge_commit_sha'] !== $merge) {
            throw new RuntimeException('Legacy REST merge evidence contradicts the GraphQL merge commit.');
        }

        return $merge;
    }

    /**
     * Reads the exact merged commit through the universal opaque repository Node ID contract.
     *
     * REST 2026 omits merge_commit_sha; GraphQL errors, nulls and partial responses fail closed.
     * The relative endpoint retains the client's same-origin GitHub Enterprise routing policy.
     *
     * @param  array{id:int,node_id:string,full_name:string} $repository Verified REST target repository identity.
     * @param  int                                           $number     Positive PR number required in both independent API responses.
     * @param  array<string,mixed>                           $rest       Complete REST PR whose head and branch names must be corroborated.
     * @return string                                        Complete SHA-1 or SHA-256 merge commit proved by the matching merged GraphQL PR.
     * @throws RuntimeException                              When GraphQL cannot corroborate every selected repository and PR identity.
     */
    private function graphMerge(array $repository, int $number, array $rest): string
    {
        [$owner, $name] = explode('/', $repository['full_name'], 2);
        $proof = $this->github->request('POST', '/graphql', [
            'query' => <<<'GRAPHQL'
query ChangelogFragmentPullRequest($owner: String!, $name: String!, $number: Int!) {
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
            'variables' => ['owner' => $owner, 'name' => $name, 'number' => $number],
        ]);
        $project = $proof['data']['repository'] ?? null;
        $pr = is_array($project) ? ($project['pullRequest'] ?? null) : null;
        $merge = is_array($pr) ? ($pr['mergeCommit']['oid'] ?? null) : null;

        if (null === $proof || (array_key_exists('errors', $proof) && [] !== $proof['errors'])
            || !is_array($project) || !is_array($pr) || ($project['id'] ?? null) !== $repository['node_id']
            || ($project['nameWithOwner'] ?? null) !== $repository['full_name']
            || ($pr['number'] ?? null) !== $number || ($pr['state'] ?? null) !== 'MERGED'
            || true !== ($pr['merged'] ?? null) || ($pr['headRefOid'] ?? null) !== $rest['head']['sha']
            || ($pr['headRefName'] ?? null) !== $rest['head']['ref'] || ($pr['baseRefName'] ?? null) !== $rest['base']['ref']
            || !is_string($merge) || !GitHubEvidence::sha($merge)) {
            throw new RuntimeException('GraphQL must corroborate the merged fragment pull request and repository.');
        }

        return $merge;
    }
}
