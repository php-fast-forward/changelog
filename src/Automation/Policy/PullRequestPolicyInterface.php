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

namespace FastForward\Changelog\Automation\Policy;

use FastForward\Changelog\Release\ReleaseOptions;

/** Resolves GitHub-backed authority; a label, title, body or local skip flag cannot grant an exception. */
interface PullRequestPolicyInterface
{
    /** Inspects a live PR snapshot and returns fail-closed proof bound to its immutable head SHA. */
    public function inspect(ReleaseOptions $options, int $prNumber, string $managedBranch = 'changelog/version', string $automationActor = 'github-actions[bot]', string $waiverLabel = 'changelog-not-required', string $maintenanceLabel = 'changelog-maintenance'): PullRequestAuthorization;
    /** Verifies signed Bot identity, receipt bytes, ancestry and exclusively managed changes at one immutable head. */
    public function inspectHead(ReleaseOptions $options, string $headSha, string $automationActor = 'github-actions[bot]', ?string $baseSha = null): bool;
}
