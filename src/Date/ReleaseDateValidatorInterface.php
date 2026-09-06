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
 * Validates release dates independently from command and persistence logic.
 */
interface ReleaseDateValidatorInterface
{
    /**
     * Accepts a real calendar date written exactly as YYYY-MM-DD.
     *
     * Implementations MUST reject malformed values and impossible calendar
     * dates before a changelog mutation reaches the manager.
     *
     * @throws InvalidArgumentException when the value is not a valid release date
     */
    public function validate(string $date): void;
}
