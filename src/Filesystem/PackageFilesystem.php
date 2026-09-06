<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Filesystem;

use Symfony\Component\Filesystem\Filesystem;
/**
 * Adapts Symfony filesystem operations to the package filesystem contract.
 *
 * All paths MUST be resolved through the injected path resolver so callers can
 * replace both I/O and path behavior in unit tests.
 */
final readonly class PackageFilesystem implements PackageFilesystemInterface
{
    /**
     * Initializes the I/O adapter and its side-effect-free path resolver.
     *
     * @param Filesystem $filesystem performs concrete filesystem operations
     * @param PackagePathResolverInterface $pathResolver resolves relative paths
     */
    public function __construct(
        private Filesystem $filesystem,
        private PackagePathResolverInterface $pathResolver,
    ) {}

    /**
     * Determines whether a resolved path exists.
     */
    public function exists(string $file, ?string $basePath = null): bool
    {
        return $this->filesystem->exists($this->getAbsolutePath($file, $basePath));
    }

    /**
     * Reads a resolved file path.
     */
    public function readFile(string $file, ?string $basePath = null): string
    {
        return $this->filesystem->readFile($this->getAbsolutePath($file, $basePath));
    }

    /**
     * Writes contents atomically through Symfony Filesystem.
     */
    public function dumpFile(string $file, string $contents, ?string $basePath = null): void
    {
        $this->filesystem->dumpFile($this->getAbsolutePath($file, $basePath), $contents);
    }

    /**
     * Creates a resolved directory with the supplied mode.
     */
    public function mkdir(string $directory, int $mode = 0o777, ?string $basePath = null): void
    {
        $this->filesystem->mkdir($this->getAbsolutePath($directory, $basePath), $mode);
    }

    /**
     * Resolves a path against an optional working directory.
     */
    public function getAbsolutePath(string $file, ?string $basePath = null): string
    {
        return $this->pathResolver->absolutePath($file, $basePath);
    }

    /**
     * Returns an ancestor directory for a path.
     */
    public function getDirectory(string $path, int $levels = 1): string
    {
        return $this->pathResolver->directoryPath($path, $levels);
    }
}
