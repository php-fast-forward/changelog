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

namespace FastForward\Changelog\Version;

use FastForward\Changelog\Changeset\Changeset;

/**
 * Aggregates the effective impacts of independently identified fragments.
 */
interface VersionImpactResolverInterface
{
    /**
     * Returns the greatest impact or null when no fragments are supplied.
     *
     * @param list<Changeset> $changesets validated pending fragments
     */
    public function resolve(array $changesets): ?VersionImpact;
}
