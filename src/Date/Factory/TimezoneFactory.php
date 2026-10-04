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

namespace FastForward\Changelog\Date\Factory;

use DateTimeZone;

/** Constructs the explicit UTC value used by release-date presentation and the runtime clock. */
final readonly class TimezoneFactory
{
    /** Returns UTC without observing the host timezone, clock or environment. */
    public function create(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
