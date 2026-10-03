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

/** Carries validated business settings shared by CLI commands and Actions. */
final readonly class ReleaseOptions
{
    /**
     * Captures explicit consumer paths and release policy without reading host state.
     * The factory MUST validate these settings before any side effect is attempted.
     */
    public function __construct(
        public string $workingDirectory,
        public string $fragmentDirectory = '.changelog',
        public string $changelogFile = 'CHANGELOG.md',
        public string $locale = 'en',
        public string $template = 'keep-a-changelog',
        public string $baseRef = 'HEAD',
        public string $tagPrefix = 'v',
        public ?string $repository = null,
        public string $source = 'auto',
    ) {}
}
