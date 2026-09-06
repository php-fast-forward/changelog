<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://github.com/php-fast-forward/changelog/issues
 * @see       https://php-fast-forward.github.io/changelog/
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Document;

/**
 * Creates changelog release value objects for parsers and managers.
 */
interface ChangelogReleaseFactoryInterface
{
    /**
     * Creates one release from its version, date, and categorized entries.
     *
     * @param array<string, list<string>> $entries
     */
    public function create(string $version, ?string $date = null, array $entries = []): ChangelogRelease;
}
