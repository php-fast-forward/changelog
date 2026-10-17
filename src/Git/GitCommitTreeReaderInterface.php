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

/** Optional read-only capability; existing GitRepositoryInterface implementations remain compatible. */
interface GitCommitTreeReaderInterface
{
    /**
     * Resolves a complete commit and its complete tree identity, including every path, mode and blob.
     *
     * @param  string                   $directory Explicit consumer directory used for repository or fragment operations.
     * @param  string                   $reference Git revision to resolve within the explicit consumer repository.
     * @return string                   Complete immutable tree identity of the selected existing commit.
     * @throws InvalidArgumentException When the selected commit reference has unsafe revision syntax.
     * @throws RuntimeException         When the commit or its complete immutable tree identity cannot be verified.
     */
    public function treeSha(string $directory, string $reference): string;
}
