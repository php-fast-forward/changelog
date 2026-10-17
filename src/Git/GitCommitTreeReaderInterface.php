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

/** Optional read-only capability; existing GitRepositoryInterface implementations remain compatible. */
interface GitCommitTreeReaderInterface
{
    /** Resolves a complete commit and its complete tree identity, including every path, mode and blob. */
    public function treeSha(string $directory, string $reference): string;
}
