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
 */

namespace FastForward\Changelog\Git;

use InvalidArgumentException;
use RuntimeException;

/** Optional immutable fragment-origin evidence; existing GitRepositoryInterface adapters remain compatible. */
interface GitFragmentOriginReaderInterface
{
    /**
     * Finds the first-parent transition that introduced the current bytes at the canonical fragment path.
     *
     * The selected base may precede HEAD and the fragment may already have been deleted in a later commit.
     * Local index/worktree state cannot change this immutable proof. Renames into the path and reintroductions
     * start a new origin; a regular-file mode change alone retains the earlier origin.
     *
     * @param string $directory Explicit consumer directory relative to which the fragment path is interpreted.
     * @param string $baseSha   Complete immutable commit whose regular fragment blob must match the accepted bytes.
     * @param string $path      Canonical consumer-relative fragment path without traversal or NUL; pathspec metacharacters stay literal.
     * @param string $sha256    Lowercase SHA-256 digest of the exact fragment bytes accepted for this operation.
     *
     * @return string|null Complete introducing commit identity, or null for missing, mismatching or unproved origin data.
     *
     * @throws InvalidArgumentException When a path, commit identity or content digest is not canonical.
     * @throws RuntimeException         When history is shallow, incomplete, malformed or exceeds 1000 path changes.
     */
    public function fragmentOrigin(string $directory, string $baseSha, string $path, string $sha256): ?string;
}
