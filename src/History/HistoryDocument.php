<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\History;

/** Retains the sole changelog history together with its raw introduction and footer. */
final class HistoryDocument
{
    /**
     * Stores releases in caller-selected order without performing I/O.
     *
     * @param list<HistoryRelease> $releases retained historical sections
     */
    public function __construct(private array $releases = [], private string $prefix = '', private string $references = '') {}

    /** Returns the release sections in their stored presentation order. */
    public function getReleases(): array
    {
        return $this->releases;
    }

    /** Returns everything preceding the first release heading unchanged. */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /** Returns the raw reference footer and any following boilerplate. */
    public function getReferences(): string
    {
        return $this->references;
    }

    /** Looks up a canonical version, accepting one conventional v prefix. */
    public function getRelease(string $version): ?HistoryRelease
    {
        $version = preg_replace('/^[vV](?=\d)/', '', $version);

        foreach ($this->releases as $release) {
            if ($release->getVersion() === $version) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Returns an independent document with the supplied section order.
     *
     * @param list<HistoryRelease> $releases replacement sections
     */
    public function withReleases(array $releases): self
    {
        $document = clone $this;
        $document->releases = $releases;

        return $document;
    }
}
