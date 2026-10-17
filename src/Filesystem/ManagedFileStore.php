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

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

/** Guards every managed-file ancestor through the injected Symfony filesystem. */
final readonly class ManagedFileStore implements ManagedFileStoreInterface
{
    /**
     * Injects I/O and diagnostic construction; construction performs no path access.
     *
     * @param Filesystem $filesystem Symfony filesystem boundary used for managed file operations.
     */
    public function __construct(
        private Filesystem $filesystem,
    ) {}

    /**
     * Reads exact contents while treating an absent regular target as null.
     *
     * @param string $path Caller-selected managed path, validated before filesystem effects.
     *
     * @return string|null Exact regular-file bytes, or null when the guarded target does not exist.
     *
     * @throws \RuntimeException                                            When path syntax or symbolic ancestry violates the managed-file policy.
     * @throws \Symfony\Component\Filesystem\Exception\IOExceptionInterface When an existing target cannot be read.
     */
    public function read(string $path): ?string
    {
        $path = $this->guard($path);

        return $this->filesystem->exists($path) ? $this->filesystem->readFile($path) : null;
    }

    /**
     * Removes only the caller-selected journal after repeated ancestry checks; an absent target is a no-op.
     *
     * @param string $path Caller-selected managed path, validated before filesystem effects.
     *
     * @return void Completion after the selected files are removed or confirmed absent.
     *
     * @throws \RuntimeException                                            When path syntax or symbolic ancestry violates the managed-file policy.
     * @throws \Symfony\Component\Filesystem\Exception\IOExceptionInterface When a target cannot be read as a file or removed.
     */
    public function remove(string $path): void
    {
        $path = $this->guard($path);
        if (! $this->filesystem->exists($path)) {
            return;
        }
        // Symfony remove accepts directories; require a readable file before delegating deletion.
        $this->filesystem->readFile($path);
        $this->guard($path);
        $this->filesystem->remove($path);
    }

    /**
     * Rechecks ancestry after parent creation and delegates the atomic replacement to Symfony.
     *
     * @param string $path     Caller-selected managed path, validated before filesystem effects.
     * @param string $contents Exact managed-file bytes supplied by the caller.
     *
     * @return void Completion after guarded replacement of the caller-selected managed file.
     *
     * @throws \RuntimeException                                            When path syntax or symbolic ancestry violates the managed-file policy.
     * @throws \Symfony\Component\Filesystem\Exception\IOExceptionInterface When parent creation or atomic replacement fails.
     */
    public function write(string $path, string $contents): void
    {
        $path = $this->guard($path);
        $directory = dirname($path);
        if (! $this->filesystem->exists($directory)) {
            $this->filesystem->mkdir($directory);
        }
        $this->guard($path);
        $this->filesystem->dumpFile($path, $contents);
    }

    /**
     * Rejects relative, traversal and symbolic paths including Windows drive and UNC ancestors.
     *
     * @param string $path Absolute managed target whose ancestry must reject symbolic links and traversal.
     *
     * @return string Guarded path with forward slashes and a normalized uppercase Windows drive letter.
     *
     * @throws \RuntimeException When the path is relative, contains traversal or has symbolic ancestry.
     */
    private function guard(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, "\0") || in_array('..', explode('/', $normalized), true)
            || in_array('.', explode('/', $normalized), true)
            || (! str_starts_with($normalized, '/') && 1 !== preg_match('~^[A-Za-z]:/~', $normalized))
            || (str_starts_with($normalized, '//') && 1 !== preg_match('~^//[^/]+/[^/]+/~', $normalized))) {
            throw new RuntimeException('Unsafe managed path: ' . $path);
        }
        if (1 === preg_match('~^[a-z]:/~', $normalized)) {
            $normalized = strtoupper($normalized[0]) . substr($normalized, 1);
        }
        $ancestor = $normalized;
        do {
            if (null !== $this->filesystem->readlink($ancestor)) {
                throw new RuntimeException('Symbolic managed path or ancestor: ' . $ancestor);
            }
            if (1 === preg_match('~^(?:[A-Za-z]:/|//[^/]+/[^/]+/?)$~D', $ancestor)) {
                break;
            }
            $parent = dirname($ancestor);
            if (1 === preg_match('~^[A-Za-z]:$~D', $parent)) {
                $parent .= '/';
            }
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        } while (true);

        return $normalized;
    }
}
