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
 * Defines the package boundary for filesystem and path operations.
 *
 * Consumers MAY replace this service to run changelog operations without a
 * physical filesystem.
 */
interface PackageFilesystemInterface
{
    /**
     * Determines whether a path exists.
     */
    public function exists(string $file, ?string $basePath = null): bool;

    /**
     * Reads a file and returns its complete contents.
     */
    public function readFile(string $file, ?string $basePath = null): string;

    /**
     * Writes complete contents to a file.
     */
    public function dumpFile(string $file, string $contents, ?string $basePath = null): void;

    /**
     * Creates a directory using the requested permissions.
     */
    public function mkdir(string $directory, int $mode = 0o777, ?string $basePath = null): void;

    /**
     * Resolves a path against an optional base path.
     */
    public function getAbsolutePath(string $file, ?string $basePath = null): string;

    /**
     * Returns an ancestor directory for a path.
     */
    public function getDirectory(string $path, int $levels = 1): string;
}
