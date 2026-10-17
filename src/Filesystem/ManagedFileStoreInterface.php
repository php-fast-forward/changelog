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
    /**
     * Reads exact bytes; missing files return null and unsafe ancestry MUST throw.
     *
     * @param string $path Caller-selected managed path, validated before filesystem effects.
     *
     * @return string|null Exact regular-file bytes, or null when the guarded target does not exist.
     *
     * @throws \RuntimeException                                            When path syntax or symbolic ancestry violates the managed-file policy.
     * @throws \Symfony\Component\Filesystem\Exception\IOExceptionInterface When an existing target cannot be read.
     */
    public function read(string $path): ?string;

    /**
     * Removes one guarded journal idempotently; the caller verifies transaction completion and holds its lock.
     *
     * @param string $path Caller-selected managed path, validated before filesystem effects.
     *
     * @return void Completion after the selected files are removed or confirmed absent.
     *
     * @throws \RuntimeException                                            When path syntax or symbolic ancestry violates the managed-file policy.
     * @throws \Symfony\Component\Filesystem\Exception\IOExceptionInterface When a target cannot be read as a file or removed.
     */
    public function remove(string $path): void;

    /**
     * Writes exact bytes after rechecking all ancestry; the caller holds the fragment-directory lock.
     *
     * @param string $path     Caller-selected managed path, validated before filesystem effects.
     * @param string $contents Exact managed-file bytes supplied by the caller.
     *
     * @return void Completion after guarded replacement of the caller-selected managed file.
     *
     * @throws \RuntimeException                                            When path syntax or symbolic ancestry violates the managed-file policy.
     * @throws \Symfony\Component\Filesystem\Exception\IOExceptionInterface When parent creation or atomic replacement fails.
     */
    public function write(string $path, string $contents): void;
}
