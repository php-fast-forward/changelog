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

namespace FastForward\Changelog\Fragment;

use FastForward\Changelog\Release\ReleaseOptions;

/** Defines creation of a single independently validated release fragment. */
interface FragmentWriterInterface
{
    /**
     * Creates a fragment without overwriting a sibling and optionally commits only it.
     *
     * Metadata is optional; explicit impact may be lower than category inference.
     * Generated-name collisions retry at most five times. Commit failure MUST
     * preserve the created path and all unrelated working/index state.
     *
     * @return string                    Absolute path of the created fragment.
     * @throws \InvalidArgumentException For schema/name/argument failures.
     * @throws \RuntimeException         For I/O, collision exhaustion or commit failures.
     */
    public function add(
        ReleaseOptions $options,
        string $message,
        string $category = 'changed',
        ?string $type = null,
        ?string $name = null,
        ?int $issue = null,
        ?int $pullRequest = null,
        ?string $author = null,
        bool $commit = false,
        string $commitMessage = 'chore: record changelog fragment',
    ): string;
}
