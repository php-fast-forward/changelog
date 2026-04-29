<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Filesystem;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function Safe\file_get_contents;
use function Safe\getcwd;

final readonly class PackageFilesystem
{
    public function __construct(
        private Filesystem $filesystem,
    ) {}

    public function exists(string $file, ?string $basePath = null): bool
    {
        return $this->filesystem->exists($this->getAbsolutePath($file, $basePath));
    }

    public function readFile(string $file, ?string $basePath = null): string
    {
        return file_get_contents($this->getAbsolutePath($file, $basePath));
    }

    public function dumpFile(string $file, string $contents, ?string $basePath = null): void
    {
        $this->filesystem->dumpFile($this->getAbsolutePath($file, $basePath), $contents);
    }

    public function mkdir(string $directory, int $mode = 0o777, ?string $basePath = null): void
    {
        $this->filesystem->mkdir($this->getAbsolutePath($directory, $basePath), $mode);
    }

    public function getAbsolutePath(string $file, ?string $basePath = null): string
    {
        $basePath ??= getcwd();

        if (! Path::isAbsolute($basePath)) {
            $basePath = Path::makeAbsolute($basePath, getcwd());
        }

        return Path::makeAbsolute($file, $basePath);
    }

    public function getDirectory(string $path, int $levels = 1): string
    {
        return \dirname($path, $levels);
    }
}
