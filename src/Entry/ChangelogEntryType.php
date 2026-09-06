<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Entry;

/**
 * Enumerates the Keep a Changelog categories supported by the package.
 *
 * Values MUST remain compatible with the canonical English section headings.
 */
enum ChangelogEntryType: string
{
    case Added = 'Added';
    case Changed = 'Changed';
    case Deprecated = 'Deprecated';
    case Removed = 'Removed';
    case Fixed = 'Fixed';
    case Security = 'Security';

}
