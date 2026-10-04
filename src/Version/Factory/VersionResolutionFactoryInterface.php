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

namespace FastForward\Changelog\Version\Factory;

use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\Version\VersionResolution;

/**
 * Creates successful and failed semantic-version resolutions.
 */
interface VersionResolutionFactoryInterface
{
    /**
     * Creates a successful resolution.
     */
    public function resolved(string $nextVersion, VersionImpact $impact): VersionResolution;

    /**
     * Creates a failed resolution with all available diagnostics.
     *
     * @param list<string> $errors version calculation diagnostics
     */
    public function invalid(array $errors): VersionResolution;
}
