<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Entry;

use InvalidArgumentException;

enum ChangelogEntryType: string
{
    case Added = 'Added';
    case Changed = 'Changed';
    case Deprecated = 'Deprecated';
    case Removed = 'Removed';
    case Fixed = 'Fixed';
    case Security = 'Security';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Added, self::Changed, self::Deprecated, self::Removed, self::Fixed, self::Security];
    }

    public static function fromInput(string $value): self
    {
        $normalized = ucfirst(strtolower(trim($value)));

        return self::tryFrom($normalized)
            ?? throw new InvalidArgumentException(\sprintf('Unsupported changelog type "%s".', $value));
    }
}
