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
    /** Package/alert association MUST come from verified metadata, not the PR body or its branch files. */
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
