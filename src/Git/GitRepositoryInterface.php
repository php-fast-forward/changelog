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

namespace FastForward\Changelog\Git;

use InvalidArgumentException;
use RuntimeException;

/** Replaces Git and process access in planning, validation and scoped commits. */
interface GitRepositoryInterface
{
    /**
     * Resolves the normalized absolute Git worktree root for repository-relative GitHub file APIs.
     *
     * @param  string           $directory Explicit consumer directory used for repository or fragment operations.
     * @return string           Canonical absolute repository root with POSIX separators.
     * @throws RuntimeException When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function repositoryRoot(string $directory): string;

    /**
     * Resolves a private per-worktree recovery journal outside the tracked tree; non-Git consumers return null.
     *
     * @param  string           $directory Explicit consumer directory used for repository or fragment operations.
     * @return ?string          Private Git-directory recovery-journal path, or null for a non-Git consumer.
     * @throws RuntimeException When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function journalPath(string $directory): ?string;

    /**
     * Reads exact release trailers bound to the approved changelog, including a bounded merged-branch search.
     *
     * Missing metadata returns null; malformed, truncated or ambiguous evidence MUST fail closed.
     *
     * @param  string                                                                                $directory     Explicit consumer directory used for repository or fragment operations.
     * @param  string                                                                                $reference     Git revision to resolve within the explicit consumer repository.
     * @param  string                                                                                $changelogFile Canonical project-relative path of the single central history blob.
     * @return array{base_sha:string,plan_id:string,output_sha256:string,options_sha256:string}|null Unique complete
     *                                                                                               transaction trailers
     *                                                                                               matching the approved
     *                                                                                               central blob, or null
     *                                                                                               when absent.
     * @throws RuntimeException                                                                      When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException                                                              When a supplied revision cannot be accepted before the immutable Git query.
     */
    public function releaseMetadata(
        string $directory,
        string $reference,
        string $changelogFile = 'CHANGELOG.md',
    ): ?array;

    /**
     * Resolves the first origin fetch URL and rewrites without network access; returns null only when origin is absent.
     *
     * @param  string           $directory Explicit consumer directory used for repository or fragment operations.
     * @return ?string          First rewritten fetch URL for origin, or null when that remote is absent.
     * @throws RuntimeException When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function originUrl(string $directory): ?string;

    /**
     * Determines whether Git can discover a worktree at the explicit consumer path.
     *
     * @param  string           $directory Explicit consumer directory used for repository or fragment operations.
     * @return bool             Whether Git recognizes the explicit directory as a working-tree repository.
     * @throws RuntimeException When Git cannot be started; a completed non-Git probe still returns false.
     */
    public function isRepository(string $directory): bool;

    /**
     * Resolves a revision to a commit SHA; invalid revisions MUST fail before mutation.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $reference Git revision to resolve within the explicit consumer repository.
     * @return string                   Complete SHA-1 or SHA-256 commit identity, never an abbreviated reference.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    public function resolveRef(string $directory, string $reference = 'HEAD'): string;

    /**
     * Verifies that a saved plan base still belongs to the effective approved history.
     *
     * @param  string                   $directory  Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $ancestor   Git revision whose ancestry must be established before accepting the descendant.
     * @param  string                   $descendant Git revision that must contain the selected ancestor in its commit history.
     * @return bool                     Whether the verified descendant contains the verified ancestor.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When a supplied revision cannot be accepted before the immutable Git query.
     */
    public function isAncestor(string $directory, string $ancestor, string $descendant = 'HEAD'): bool;

    /**
     * Lists tags with creation dates only for annotated tags, never inferred from commits.
     *
     * @param  string                                                               $directory Explicit consumer directory used for repository or fragment operations.
     * @return list<array{name:string,sha:string,date:?string,date_source:?string}> Complete tag records with
     *                                                                              dereferenced commit identities and
     *                                                                              annotated-date provenance.
     * @throws RuntimeException                                                     When Git execution or the returned immutable repository evidence cannot be verified.
     */
    public function tags(string $directory): array;

    /**
     * Compares the merge base with HEAD using NUL-delimited paths, including renames.
     *
     * @param  string                                                  $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                                                  $reference Git revision to resolve within the explicit consumer repository.
     * @return list<array{status:string,path:string,previous:?string}> Ordered status/path records retaining rename and
     *                                                                 copy source paths.
     * @throws RuntimeException                                        When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException                                When a supplied revision cannot be accepted before the immutable Git query.
     */
    public function changesSince(string $directory, string $reference): array;

    /**
     * Reads baseline bytes; a missing path returns null, while a failed revision MUST throw.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $reference Git revision to resolve within the explicit consumer repository.
     * @param  string                   $path      Canonical repository-relative managed path selected for this operation.
     * @return ?string                  Exact committed blob bytes, or null when the verified tree contains no selected entry.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    public function readFileAt(string $directory, string $reference, string $path): ?string;

    /**
     * Inventories committed entries beneath a consumer-relative path without following links.
     *
     * @param  string                               $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                               $reference Git revision to resolve within the explicit consumer repository.
     * @param  string                               $path      Canonical repository-relative managed path selected for this operation.
     * @return list<array{path:string,mode:string}> Committed path/mode records retaining unusual filename bytes.
     * @throws RuntimeException                     When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException             When the supplied revision, managed path or commit input is not acceptable.
     */
    public function filesAt(string $directory, string $reference, string $path): array;

    /**
     * Stages and commits only the generated path, preserving unrelated index entries.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $path      Canonical repository-relative managed path selected for this operation.
     * @param  string                   $message   Controlled diagnostic or commit message supplied by the owning operation.
     * @return string                   New complete HEAD identity after staging and committing only the selected fragment.
     * @throws RuntimeException         When Git execution or the returned immutable repository evidence cannot be verified.
     * @throws InvalidArgumentException When the supplied revision, managed path or commit input is not acceptable.
     */
    public function commitFragment(string $directory, string $path, string $message): string;
}
