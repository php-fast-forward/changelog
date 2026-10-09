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

/** Validates explicit trusted workflow metadata before any privileged request. */
interface DependabotInputFactoryInterface
{
    /** Rejects unsafe names, incomplete object IDs and ambiguous metadata; names/alerts become sorted unique lists. */
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
