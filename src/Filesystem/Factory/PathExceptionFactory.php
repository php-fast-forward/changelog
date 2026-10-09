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

use InvalidArgumentException;

/** Constructs path-policy exceptions at the object-construction boundary. */
final readonly class PathExceptionFactory implements PathExceptionFactoryInterface
{
    /** Identifies the rejected path without performing any inspection or I/O. */
    public function create(string $path): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Unsafe fragment path "%s": use an absolute regular path without traversal.',
            $path,
        ));
    }
}
