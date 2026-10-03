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

namespace FastForward\Changelog\Date;

use InvalidArgumentException;

/**
 * Validates strict ISO-style release dates without a system clock dependency.
 */
final readonly class ReleaseDateValidator implements ReleaseDateValidatorInterface
{
    /**
     * Validates the date format and Gregorian calendar components.
     *
     * The method MUST reject alternate separators, omitted zero-padding, and
     * calendar dates that do not exist. It MUST NOT read the system clock.
     *
     * @throws InvalidArgumentException when the value is not a valid release date
     */
    public function validate(string $date): void
    {
        if (
            1 !== preg_match('/\A(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})\z/', $date, $matches)
            || ! checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year'])
        ) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid release date "%s"; expected a real calendar date in YYYY-MM-DD format.',
                $date,
            ));
        }
    }
}
