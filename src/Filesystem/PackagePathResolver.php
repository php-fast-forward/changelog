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
 * Resolves package paths against an injected process working directory.
 */
final readonly class PackagePathResolver implements PackagePathResolverInterface
{
    /**
     * Captures the composition root used when no working directory is supplied.
     *
     * @param string $currentWorkingDirectory absolute composition-root directory
     */
    public function __construct(
        private string $currentWorkingDirectory,
    ) {}

    /**
     * Resolves a path without reading or writing the filesystem.
     */
    public function absolutePath(string $path, ?string $workingDirectory = null): string
    {
        $workingDirectory ??= $this->currentWorkingDirectory;

        if (! $this->isAbsolute($workingDirectory)) {
            $workingDirectory = $this->normalize($this->currentWorkingDirectory . '/' . $workingDirectory);
        }

        if ($this->isAbsolute($path)) {
            return $this->normalize($path);
        }

        return $this->normalize($workingDirectory . '/' . $path);
    }

    /**
     * Returns the requested ancestor directory without filesystem access.
     */
    public function directoryPath(string $path, int $levels = 1): string
    {
        return \dirname($this->normalize($path), $levels);
    }

    /**
     * Determines whether a path is absolute without filesystem access.
     */
    public function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || 1 === preg_match('/^[a-zA-Z]:[\\\\\/]/', $path);
    }

    /**
     * Makes an absolute path relative to a base directory.
     */
    public function relativePath(string $path, string $basePath): string
    {
        $path = $this->normalize($path);
        $basePath = $this->normalize($basePath);
        $pathRoot = $this->root($path);
        $baseRoot = $this->root($basePath);

        if (strtolower($pathRoot) !== strtolower($baseRoot)) {
            return $path;
        }

        $pathParts = $this->parts($path, $pathRoot);
        $baseParts = $this->parts($basePath, $baseRoot);

        while ([] !== $pathParts && [] !== $baseParts && $pathParts[0] === $baseParts[0]) {
            array_shift($pathParts);
            array_shift($baseParts);
        }

        return implode('/', [...array_fill(0, \count($baseParts), '..'), ...$pathParts]);
    }

    /**
     * Normalizes separators and dot segments without consulting the filesystem.
     */
    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = $this->root($path);
        $parts = [];

        foreach ($this->parts($path, $root) as $part) {
            if ('.' === $part || '' === $part) {
                continue;
            }

            if ('..' === $part && [] !== $parts && '..' !== $parts[array_key_last($parts)]) {
                array_pop($parts);

                continue;
            }

            if ('..' !== $part || '' === $root) {
                $parts[] = $part;
            }
        }

        $normalized = $root . implode('/', $parts);

        return '' === $normalized && '' !== $root ? $root : $normalized;
    }

    /**
     * Returns the syntactic root of a normalized path.
     */
    private function root(string $path): string
    {
        if (str_starts_with($path, '//')) {
            return '//';
        }

        if (1 === preg_match('/^[a-zA-Z]:\//', $path)) {
            return substr($path, 0, 3);
        }

        return str_starts_with($path, '/') ? '/' : '';
    }

    /**
     * Splits the path portion after its root into non-empty segments.
     *
     * @return list<string>
     */
    private function parts(string $path, string $root): array
    {
        return array_values(array_filter(explode('/', substr($path, \strlen($root))), static fn(string $part): bool => '' !== $part));
    }
}
