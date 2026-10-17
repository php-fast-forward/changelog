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

namespace FastForward\Changelog\Release;

/** Places interrupted transaction state outside every versioned consumer directory. */
interface ReleaseJournalPathResolverInterface
{
    /**
     * Returns a worktree-local Git path or an injected temporary path for a non-Git consumer.
     *
     * @param ReleaseOptions $options Validated consumer paths, presentation settings and source-selection policy.
     *
     * @return string Private recovery-journal path in the Git administrative directory or injected temporary storage.
     */
    public function resolve(ReleaseOptions $options): string;
}
