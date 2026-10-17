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

namespace FastForward\Changelog\Automation\Dependabot;

/** Holds trusted metadata collected from the base workflow and bound to one reviewed PR head. */
final readonly class DependabotInput
{
    /**
     * Package/alert association MUST come from verified metadata, not the PR body or its branch files.
     *
     * @param int          $pullRequest          Positive pull-request number associated with the approved automation snapshot.
     * @param string       $expectedHeadSha      Complete Dependabot PR head bound to the verified metadata snapshot.
     * @param list<string> $packageNames         Explicit updated package identifiers collected from verified Dependabot
     *                                           metadata.
     * @param string       $dependencyType       Verified production, development or indirect dependency scope.
     * @param string       $ecosystem            Verified dependency ecosystem, with the upstream GitHub Actions alias normalized by the
     *                                           factory.
     * @param list<int>    $securityAlertNumbers Positive PR-associated alert numbers that require current matching
     *                                           security evidence.
     * @param bool         $includeDev           Whether direct development dependency updates may generate a fragment.
     * @param bool         $includeActions       Whether GitHub Actions dependency updates may generate a fragment.
     */
    public function __construct(
        public int $pullRequest,
        public string $expectedHeadSha,
        public array $packageNames,
        public string $dependencyType,
        public string $ecosystem,
        public array $securityAlertNumbers = [],
        public bool $includeDev = true,
        public bool $includeActions = true,
    ) {}
}
