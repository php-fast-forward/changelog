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

use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestException;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;

/** Validates automation-only branch and identity settings before privileged use. */
interface VersionPullRequestInputFactoryInterface
{
    /**
     * Rejects unsafe refs, Bot logins and controls; bounds literal titles to 256 bytes and version templates to 1024 bytes.
     *
     * @param  string                      $baseBranch      Trusted release-line branch that supplies the fresh source base.
     * @param  string                      $managedBranch   Exclusive automation branch reserved for the generated version PR.
     * @param  string                      $automationActor Canonical GitHub Bot login required to own and sign generated commits.
     * @param  string                      $title           Single-line PR title or supported version-placeholder template; never publication authority.
     * @param  bool                        $dryRun          Whether to return the proposed transaction without creating or updating remote content.
     * @return VersionPullRequestInput     Validated immutable branch, Bot, title and preview settings.
     * @throws VersionPullRequestException When branch, Bot identity or bounded single-line title settings are invalid.
     */
    public function create(
        string $baseBranch = 'main',
        string $managedBranch = 'changelog/version',
        string $automationActor = 'github-actions[bot]',
        string $title = 'chore: update changelog',
        bool $dryRun = false,
    ): VersionPullRequestInput;
}
