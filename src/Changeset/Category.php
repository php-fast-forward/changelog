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

use FastForward\Changelog\Version\VersionImpact;

/**
 * Lists the public Keep a Changelog categories accepted by a changeset.
 *
 * Values MUST remain lowercase in fragment frontmatter. Headings SHALL match
 * the canonical Keep a Changelog section names when fragments are collected.
 */
enum Category: string
{
    case Added = 'added';
    case Changed = 'changed';
    case Deprecated = 'deprecated';
    case Removed = 'removed';
    case Fixed = 'fixed';
    case Security = 'security';

    /**
     * Returns the canonical Markdown heading for this category.
     */
    public function heading(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Returns the release impact inferred when a fragment has no override.
     *
     * Removed entries imply major. Added, changed and deprecated entries imply
     * minor; fixes and security entries imply patch. Explicit fragment impact
     * MAY override this default in either direction.
     */
    public function inferredImpact(): VersionImpact
    {
        return match ($this) {
            self::Removed => VersionImpact::Major,
            self::Added, self::Changed, self::Deprecated => VersionImpact::Minor,
            self::Fixed, self::Security => VersionImpact::Patch,
        };
    }
}
