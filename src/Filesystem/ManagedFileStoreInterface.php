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

namespace FastForward\Changelog\Filesystem;

/** Defines guarded central changelog and receipt access, independently of fragment creation. */
interface ManagedFileStoreInterface
{
    /** Reads exact bytes; missing files return null and unsafe ancestry MUST throw. */
    public function read(string $path): ?string;

    /** Writes exact bytes after rechecking all ancestry; the caller holds the fragment-directory lock. */
    public function write(string $path, string $contents): void;
}
