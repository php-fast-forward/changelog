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
     *
     * @param string      $path             Input path interpreted syntactically without reading filesystem state.
     * @param string|null $workingDirectory Optional resolution base; null selects the injected composition root, and relative bases resolve against it.
     *
     * @return string Normalized absolute path resolved against the supplied directory or injected composition root.
     */
    public function absolutePath(string $path, ?string $workingDirectory = null): string;

    /**
     * Returns an ancestor directory for a path.
     *
     * @param string $path   Input path interpreted syntactically without reading filesystem state.
     * @param int    $levels Positive number of syntactic parent directories to ascend.
     *
     * @return string Syntactic ancestor path after ascending the requested number of levels.
     */
    public function directoryPath(string $path, int $levels = 1): string;

    /**
     * Determines whether a path is absolute.
     *
     * @param string $path Input path interpreted syntactically without reading filesystem state.
     *
     * @return bool Whether the syntax identifies a POSIX, drive-rooted or UNC absolute path.
     */
    public function isAbsolute(string $path): bool;

    /**
     * Makes an absolute path relative to a base directory.
     *
     * @param string $path     Input path interpreted syntactically without reading filesystem state.
     * @param string $basePath Base directory used for the relative-path calculation.
     *
     * @return string Relative path from the base when roots agree; otherwise the normalized absolute target.
     */
    public function relativePath(string $path, string $basePath): string;
}
