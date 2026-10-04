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

namespace FastForward\Changelog\Changeset;

/**
 * Represents the semantic-version impact contributed by one changeset.
 *
 * The enum describes an impact, not a concrete published version. An explicit
 * fragment value overrides its category; aggregation uses the greatest impact.
 */
enum VersionImpact: string
{
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';

    /**
     * Returns the ordering weight used to compare release impacts.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Patch => 1,
            self::Minor => 2,
            self::Major => 3,
        };
    }

    /**
     * Returns the greater of this impact and another impact.
     *
     * Aggregation MUST be monotonic so adding a fragment cannot reduce the
     * version selected for the pending release.
     */
    public function elevate(VersionImpact $other): VersionImpact
    {
        return $other->weight() > $this->weight() ? $other : $this;
    }
}
