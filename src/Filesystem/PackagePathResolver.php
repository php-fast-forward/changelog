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

use InvalidArgumentException;

/**
 * Resolves package paths against an injected process working directory.
 */
final readonly class PackagePathResolver implements PackagePathResolverInterface
{
    /**
     * Captures the composition root used when no working directory is supplied.
     *
     * @param string $currentWorkingDirectory absolute composition-root directory
     *
     * @throws InvalidArgumentException When the injected composition directory is not syntactically absolute.
     */
    public function __construct(
        private string $currentWorkingDirectory,
    ) {
        if (! $this->isAbsolute($this->currentWorkingDirectory)) {
            throw new InvalidArgumentException('The composition working directory must be absolute.');
        }
    }

    /**
     * Resolves a path without reading or writing the filesystem.
     *
     * @param string      $path             Input path interpreted syntactically without reading filesystem state.
     * @param string|null $workingDirectory Optional resolution base; null selects the injected composition root, and relative bases resolve against it.
     *
     * @return string Normalized absolute path resolved against the supplied directory or injected composition root.
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
     *
     * @param string $path   Input path interpreted syntactically without reading filesystem state.
     * @param int    $levels Positive number of syntactic parent directories to ascend.
     *
     * @return string Syntactic ancestor path after ascending the requested number of levels.
     */
    public function directoryPath(string $path, int $levels = 1): string
    {
        return \dirname($this->normalize($path), $levels);
    }

    /**
     * Determines whether a path is absolute without filesystem access.
     *
     * @param string $path Input path interpreted syntactically without reading filesystem state.
     *
     * @return bool Whether the syntax identifies a POSIX, drive-rooted or UNC absolute path.
     */
    public function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || 1 === preg_match('/^[a-zA-Z]:[\\\\\/]/', $path);
    }

    /**
     * Makes an absolute path relative to a base directory.
     *
     * @param string $path     Input path interpreted syntactically without reading filesystem state.
     * @param string $basePath Base directory used for the relative-path calculation.
     *
     * @return string Relative path from the base when roots agree; otherwise the normalized absolute target.
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

        $caseInsensitive = '/' !== $pathRoot && '' !== $pathRoot;

        while ([] !== $pathParts && [] !== $baseParts
            && ($caseInsensitive ? 0 === strcasecmp($pathParts[0], $baseParts[0]) : $pathParts[0] === $baseParts[0])
        ) {
            array_shift($pathParts);
            array_shift($baseParts);
        }

        return implode('/', [...array_fill(0, \count($baseParts), '..'), ...$pathParts]);
    }

    /**
     * Normalizes separators and dot segments without consulting the filesystem.
     *
     * @param string $path Input path interpreted syntactically without reading filesystem state.
     *
     * @return string Path with canonical separators and dot segments normalized without filesystem reads.
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

            if ('..' === $part && [] !== $parts && '..' !== array_last($parts)) {
                array_pop($parts);

                continue;
            }

            if ('..' !== $part || '' === $root) {
                $parts[] = $part;
            }
        }

        $normalized = $root . implode('/', $parts);

        if (str_starts_with($root, '//') && '//' !== $root && [] === $parts) {
            return rtrim($root, '/');
        }

        return '' === $normalized && '' !== $root ? $root : $normalized;
    }

    /**
     * Returns the syntactic root of a normalized path.
     *
     * @param string $path Input path interpreted syntactically without reading filesystem state.
     *
     * @return string Syntactic POSIX, drive or UNC root, or an empty string for a relative path.
     */
    private function root(string $path): string
    {
        if (1 === preg_match('~^//[^/]+/[^/]+(?:/|$)~', $path, $matches)) {
            return rtrim($matches[0], '/') . '/';
        }

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
     * @param string $path Input path interpreted syntactically without reading filesystem state.
     * @param string $root Previously determined syntactic path root, including drive or UNC prefix when present.
     *
     * @return list<string> Nonempty path segments following the previously identified root.
     */
    private function parts(string $path, string $root): array
    {
        return array_values(
            array_filter(explode('/', substr($path, \strlen($root))), static fn(string $part): bool => '' !== $part),
        );
    }
}
