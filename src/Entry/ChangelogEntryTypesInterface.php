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

namespace FastForward\Changelog\Entry;

/**
 * Defines category ordering and user-input resolution for changelog entries.
 *
 * Implementations MUST return the canonical Keep a Changelog order and MUST
 * reject values that do not identify a supported category.
 */
interface ChangelogEntryTypesInterface
{
    /**
     * Returns categories in their canonical rendering order.
     *
     * @return list<ChangelogEntryType>
     */
    public function ordered(): array;

    /**
     * Resolves a case-insensitive user value to a supported category.
     *
     * @throws \InvalidArgumentException when the value is unsupported
     */
    public function fromInput(string $value): ChangelogEntryType;
}
