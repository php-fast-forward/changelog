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

namespace FastForward\Changelog\Automation\VersionPullRequest\Factory;

use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;

/** Validates automation-only branch and identity settings before privileged use. */
interface VersionPullRequestInputFactoryInterface
{
    /** Rejects unsafe refs, equal branches, invalid Bot logins and multiline/oversized PR titles. */
    public function create(
        string $baseBranch = 'main',
        string $managedBranch = 'changelog/version',
        string $automationActor = 'github-actions[bot]',
        string $title = 'chore: update changelog',
        bool $dryRun = false,
    ): VersionPullRequestInput;
}
