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

/** Replaces Git and process access in planning, validation and scoped commits. */
interface GitRepositoryInterface
{
    /** Resolves the normalized absolute Git worktree root for repository-relative GitHub file APIs. */
    public function repositoryRoot(string $directory): string;

    /** Determines whether Git can discover a worktree at the explicit consumer path. */
    public function isRepository(string $directory): bool;

    /** Resolves a revision to a commit SHA; invalid revisions MUST fail before mutation. */
    public function resolveRef(string $directory, string $reference = 'HEAD'): string;

    /** Verifies that a saved plan base still belongs to the effective approved history. */
    public function isAncestor(string $directory, string $ancestor, string $descendant = 'HEAD'): bool;

    /**
     * Lists tags with creation dates only for annotated tags, never inferred from commits.
     * @return list<array{name:string,sha:string,date:?string,date_source:?string}>
     */
    public function tags(string $directory): array;

    /**
     * Compares the merge base with HEAD using NUL-delimited paths, including renames.
     * @return list<array{status:string,path:string,previous:?string}>
     */
    public function changesSince(string $directory, string $reference): array;

    /** Reads baseline bytes; a missing path returns null, while a failed revision MUST throw. */
    public function readFileAt(string $directory, string $reference, string $path): ?string;

    /**
     * Inventories committed entries beneath a consumer-relative path without following links.
     * @return list<array{path:string,mode:string}> modes preserve symlinks and submodules for caller rejection
     */
    public function filesAt(string $directory, string $reference, string $path): array;

    /** Stages and commits only the generated path, preserving unrelated index entries. */
    public function commitFragment(string $directory, string $path, string $message): string;
}
