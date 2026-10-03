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

namespace FastForward\Changelog\Changeset\Store;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use UnexpectedValueException;

/**
 * Defines the filesystem operations expressed in changeset-domain terms.
 */
interface ChangesetStoreInterface
{
    /**
     * Returns the directory-scoped resource shared by add and version transactions.
     *
     * The directory MUST be absolute and contain no parent traversal.
     */
    public function lockResource(string $directory): string;

    /**
     * Inspects whether an exact managed path can receive a new fragment.
     *
     * Implementations MUST return `Available`, `Existing`, or `UnsafePath` and
     * MUST apply the same symbolic-path policy used by the locked write.
     *
     * @throws IOExceptionInterface when the path cannot be inspected
     */
    public function inspectWrite(string $path): WriteResult;

    /**
     * Lists every Markdown candidate in deterministic path order.
     *
     * `.changelog/AGENTS.md` MUST be excluded because it is reserved for local
     * instructions. Hidden and nested Markdown MUST remain visible so the
     * validation layer can reject names and layouts that violate policy.
     *
     * @return list<string>|null absolute Markdown paths;
     *                           null when the discovery root is unsafe
     *
     * @throws DirectoryNotFoundException when an existing root is not a directory
     * @throws UnexpectedValueException   when an existing root cannot be traversed
     */
    public function paths(string $directory): ?array;

    /**
     * Reads one fragment without interpreting its contents.
     *
     * @return string|null contents, or null when the path or any ancestor is symbolic
     *
     * @throws IOExceptionInterface when a regular fragment cannot be read
     */
    public function read(string $path): ?string;

    /**
     * Writes a new fragment without replacing an existing identifier.
     *
     * Implementations MUST serialize their existence check and write, and MUST
     * reject symbolic file and ancestor paths.
     *
     * The result MUST distinguish successful creation, an existing ID, an
     * unsafe symbolic path, and failure to acquire the serialization lock.
     *
     * @throws IOExceptionInterface when the directory or file cannot be written
     */
    public function write(string $path, string $contents): WriteResult;

    /**
     * Removes selected fragments after preflighting every path; empty input is a no-op.
     *
     * The caller MUST hold lockResource(directory) across its release transaction.
     * Missing targets are idempotent; unsafe selections fail before any removal.
     *
     * @throws \InvalidArgumentException when an explicit path is unsafe
     *
     * @param list<string> $paths exact paths selected by a release plan
     *
     * @throws IOExceptionInterface when a selected path cannot be removed
     */
    public function remove(array $paths): void;
}
