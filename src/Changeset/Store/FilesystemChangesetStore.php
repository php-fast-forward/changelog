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

use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface;
use FastForward\Changelog\Filesystem\Factory\PathExceptionFactoryInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Lock\LockFactory;

/**
 * Performs fragment I/O with exclusive cooperative creation and ancestry checks.
 *
 * The composition root supplies absolute paths. The adapter rejects traversal
 * and every symbolic ancestor before access. Release application MUST acquire
 * the same directory resource for its read/plan/write/remove transaction.
 */
final readonly class FilesystemChangesetStore implements ChangesetStoreInterface
{
    /**
     * Injects filesystem, discovery, locking and exception-construction boundaries.
     */
    public function __construct(
        private Filesystem $filesystem,
        private FinderFactoryInterface $finderFactory,
        private LockFactory $lockFactory,
        private PathExceptionFactoryInterface $exceptionFactory,
    ) {}

    /**
     * Returns the shared transaction resource for one absolute fragment directory.
     *
     * Equivalent separator/dot spellings share a lock. Parent traversal and
     * relative paths fail before a lock can be constructed.
     */
    public function lockResource(string $directory): string
    {
        $normalized = $this->normalizePath($directory);

        if (null === $normalized) {
            throw $this->exceptionFactory->create($directory);
        }

        return 'fast-forward/changelog:' . hash('sha256', $normalized);
    }

    /**
     * Checks the immutable filename, every symbolic ancestor and existing target.
     */
    public function inspectWrite(string $path): WriteResult
    {
        $path = $this->normalizePath($path);

        if (null === $path
            || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, basename($path))
            || $this->isUnsafePath($path)
        ) {
            return WriteResult::UnsafePath;
        }

        return $this->filesystem->exists($path) ? WriteResult::Existing : WriteResult::Available;
    }

    /**
     * Discovers Markdown candidates without hiding invalid nested/hidden layouts.
     *
     * Only the root-level AGENTS.md is reserved. Validation owns diagnostics
     * for all other candidates. An absent directory is a read-only empty result.
     *
     * @return list<string>|null Paths, or null for unsafe root ancestry.
     */
    public function paths(string $directory): ?array
    {
        $directory = $this->normalizePath($directory);

        if (null === $directory || $this->isUnsafePath($directory)) {
            return null;
        }

        if (! $this->filesystem->exists($directory)) {
            return [];
        }

        $finder = $this->finderFactory->create();
        $finder->filter(static fn(SplFileInfo $file): bool => $file->isFile() || $file->isLink());
        $finder->ignoreDotFiles(false);
        $finder->ignoreVCS(false);
        $finder->name('*.md');
        $finder->sortByName();
        $finder->in($directory);
        $paths = [];

        foreach ($finder->getIterator() as $file) {
            if ($file instanceof SplFileInfo && 'AGENTS.md' !== $file->getRelativePathname()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Reads original contents only after every ancestor passes symbolic checks.
     */
    public function read(string $path): ?string
    {
        $path = $this->normalizePath($path);

        if (null === $path || $this->isUnsafePath($path)) {
            return null;
        }

        return $this->filesystem->readFile($path);
    }

    /**
     * Creates only the parent and fragment after an under-lock existence check.
     *
     * A cooperating second writer observes Existing. Locks are released on every
     * result and exception; a failed write leaves its recoverable filesystem state.
     */
    public function write(string $path, string $contents): WriteResult
    {
        $path = $this->normalizePath($path);

        if (null === $path || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, basename($path))) {
            return WriteResult::UnsafePath;
        }

        $directory = dirname($path);
        $lock = $this->lockFactory->createLock($this->lockResource($directory));

        if (! $lock->acquire(true)) {
            return WriteResult::LockUnavailable;
        }

        try {
            $inspection = $this->inspectWrite($path);

            if (WriteResult::Available !== $inspection) {
                return $inspection;
            }

            if (! $this->filesystem->exists($directory)) {
                $this->filesystem->mkdir($directory);
            }

            if ($this->isUnsafePath($path)) {
                return WriteResult::UnsafePath;
            }

            $this->filesystem->dumpFile($path, $contents);

            return WriteResult::Created;
        } finally {
            $lock->release();
        }
    }

    /**
     * Preflights the complete explicit selection before removing any fragment.
     *
     * Missing files are an idempotent no-op in Symfony Filesystem. No wildcard,
     * reserved filename or symbolic ancestry can be removed through this method.
     * The caller holds lockResource(directory) throughout release application.
     *
     * @param list<string> $paths Exact consumed fragment paths from a release plan.
     */
    public function remove(array $paths): void
    {
        $normalizedPaths = [];

        foreach ($paths as $path) {
            $normalized = $this->normalizePath($path);

            if (null === $normalized
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, basename($normalized))
                || $this->isUnsafePath($normalized)
            ) {
                throw $this->exceptionFactory->create($path);
            }

            $normalizedPaths[] = $normalized;
        }

        if ([] !== $normalizedPaths) {
            $this->filesystem->remove(array_values(array_unique($normalizedPaths)));
        }
    }

    /**
     * Walks the final path and every ancestor through the injected filesystem.
     */
    private function isUnsafePath(string $path): bool
    {
        do {
            if (null !== $this->filesystem->readlink($path)) {
                return true;
            }

            if (1 === preg_match('/^(?:[A-Za-z]:\/|\/\/[^\/]+\/[^\/]+\/?)$/D', $path)) {
                return false;
            }

            $parent = dirname($path);

            if (1 === preg_match('/^[A-Za-z]:$/D', $parent)) {
                $parent .= '/';
            }

            if ($parent === $path || '.' === $parent) {
                return false;
            }

            $path = $parent;
        } while (true);
    }

    /**
     * Normalizes absolute separators and current segments, rejecting traversal.
     *
     * Lexical validation performs no filesystem/host-state access. The caller
     * resolves cwd at composition time and provides its physical absolute root.
     */
    private function normalizePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, "\0")) {
            return null;
        }

        if (str_starts_with($path, '//')) {
            if (1 !== preg_match('~^//(?<server>[^/]+)/(?<share>[^/]+)(?:/|$)~', $path, $matches)
                || in_array($matches['server'], ['.', '..'], true)
                || in_array($matches['share'], ['.', '..'], true)
            ) {
                return null;
            }

            $root = '//' . $matches['server'] . '/' . $matches['share'] . '/';
        } elseif (str_starts_with($path, '/')) {
            $root = '/';
        } elseif (1 === preg_match('/^[A-Za-z]:\//', $path)) {
            $root = strtoupper($path[0]) . ':/';
        } else {
            return null;
        }

        $segments = [];

        foreach (explode('/', substr($path, strlen($root))) as $segment) {
            if ('..' === $segment) {
                return null;
            }

            if ('' !== $segment && '.' !== $segment) {
                $segments[] = $segment;
            }
        }

        return $root . implode('/', $segments);
    }
}
