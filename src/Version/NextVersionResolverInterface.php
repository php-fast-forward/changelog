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
 * Resolves a concrete next version from a current version and fragments.
 */
interface NextVersionResolverInterface
{
    /**
     * Calculates the next numeric semantic version.
     *
     * A `v` prefix and valid build metadata MAY be present in the current
     * stable version. Prerelease inputs are outside the first-release contract.
     *
     * @param list<Changeset> $changesets validated pending fragments
     */
    public function resolve(string $currentVersion, array $changesets): VersionResolution;
}
