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

namespace FastForward\Changelog\Filesystem\Factory;

use Symfony\Component\Finder\Finder;

/**
 * Provides the construction boundary for Symfony Finder.
 */
final readonly class FinderFactory implements FinderFactoryInterface
{
    /**
     * Creates an unconfigured Finder without touching the filesystem.
     */
    public function create(): Finder
    {
        return new Finder();
    }
}
