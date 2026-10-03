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

namespace FastForward\Changelog\Configuration\Factory;

use FastForward\Config\ConfigInterface;

/** Creates a lazy source for an explicitly selected trusted PHP presentation template. */
interface ConfigSourceFactoryInterface
{
    /** Constructs without reading or writing the source; loading is an explicit later operation. */
    public function create(string $absolutePath): ConfigInterface;
}
