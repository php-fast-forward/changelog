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

namespace FastForward\Changelog\Filesystem;

/**
 * Defines deterministic path resolution independently from filesystem I/O.
 */
interface PackagePathResolverInterface
{
    /**
     * Resolves a path against an optional working directory.
     */
    public function absolutePath(string $path, ?string $workingDirectory = null): string;

    /**
     * Returns an ancestor directory for a path.
     */
    public function directoryPath(string $path, int $levels = 1): string;

    /**
     * Determines whether a path is absolute.
     */
    public function isAbsolute(string $path): bool;

    /**
     * Makes an absolute path relative to a base directory.
     */
    public function relativePath(string $path, string $basePath): string;
}
