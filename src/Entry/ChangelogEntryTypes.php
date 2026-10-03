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

use InvalidArgumentException;

/**
 * Provides the canonical category order and normalizes command input.
 */
final readonly class ChangelogEntryTypes implements ChangelogEntryTypesInterface
{
    /**
     * Returns categories in the order required by Keep a Changelog.
     *
     * @return list<ChangelogEntryType>
     */
    public function ordered(): array
    {
        return [
            ChangelogEntryType::Added,
            ChangelogEntryType::Changed,
            ChangelogEntryType::Deprecated,
            ChangelogEntryType::Removed,
            ChangelogEntryType::Fixed,
            ChangelogEntryType::Security,
        ];
    }

    /**
     * Resolves a normalized category name.
     *
     * The resolver MUST trim surrounding whitespace and compare without case.
     *
     * @throws InvalidArgumentException when the value is unsupported
     */
    public function fromInput(string $value): ChangelogEntryType
    {
        return match (strtolower(trim($value))) {
            'added' => ChangelogEntryType::Added,
            'changed' => ChangelogEntryType::Changed,
            'deprecated' => ChangelogEntryType::Deprecated,
            'removed' => ChangelogEntryType::Removed,
            'fixed' => ChangelogEntryType::Fixed,
            'security' => ChangelogEntryType::Security,
            default => throw new InvalidArgumentException(\sprintf('Unsupported changelog type "%s".', $value)),
        };
    }
}
