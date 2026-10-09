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

namespace FastForward\Changelog\Automation\VersionPullRequest;

/** Contains exclusive automation settings supplied by trusted composition, never head configuration. */
final readonly class VersionPullRequestInput
{
    /** Captures the managed branch policy; its factory MUST validate Git refs and presentation limits. */
    public function __construct(
        public string $baseBranch = 'main',
        public string $managedBranch = 'changelog/version',
        public string $automationActor = 'github-actions[bot]',
        public string $title = 'chore: update changelog',
        public bool $dryRun = false,
    ) {}
}
