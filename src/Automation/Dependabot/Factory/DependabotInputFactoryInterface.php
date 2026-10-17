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

namespace FastForward\Changelog\Automation\Dependabot\Factory;

use FastForward\Changelog\Automation\Dependabot\DependabotInput;
use RuntimeException;

/** Validates explicit trusted workflow metadata before any privileged request. */
interface DependabotInputFactoryInterface
{
    /**
     * Rejects unsafe names, incomplete object IDs and ambiguous metadata; names/alerts become sorted unique lists.
     *
     * Normalizes the exact upstream github_actions ecosystem to canonical github-actions.
     *
     * @param  int              $pullRequest          Positive pull-request number associated with the approved automation snapshot.
     * @param  string           $expectedHeadSha      Complete Dependabot PR head bound to the verified metadata snapshot.
     * @param  list<string>     $packageNames         Explicit updated package identifiers collected from verified Dependabot
     *                                                metadata.
     * @param  string           $dependencyType       Verified production, development or indirect dependency scope.
     * @param  string           $ecosystem            Verified dependency ecosystem, with the upstream GitHub Actions alias normalized by the
     *                                                factory.
     * @param  list<int>        $securityAlertNumbers Positive PR-associated alert numbers that require current matching
     *                                                security evidence.
     * @param  bool             $includeDev           Whether direct development dependency updates may generate a fragment.
     * @param  bool             $includeActions       Whether GitHub Actions dependency updates may generate a fragment.
     * @return DependabotInput  Validated deterministic dependency metadata with deduplicated sorted packages and alerts.
     * @throws RuntimeException When trusted package, scope, ecosystem, PR/head or alert metadata is unsafe or
     *                          unsupported.
     */
    public function create(
        int $pullRequest,
        string $expectedHeadSha,
        array $packageNames,
        string $dependencyType,
        string $ecosystem,
        array $securityAlertNumbers = [],
        bool $includeDev = true,
        bool $includeActions = true,
    ): DependabotInput;
}
