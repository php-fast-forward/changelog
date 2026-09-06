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

namespace FastForward\Changelog\Document;

use FastForward\Changelog\Entry\ChangelogEntryType;

/**
 * Represents one immutable changelog release and its categorized entries.
 *
 * Instances MUST deduplicate entries within each category. Mutation methods
 * return a clone so callers can safely retain earlier release snapshots.
 */
final class ChangelogRelease
{
    /**
     * @var array<string, list<string>>
     */
    private array $entries = [
        'Added' => [],
        'Changed' => [],
        'Deprecated' => [],
        'Removed' => [],
        'Fixed' => [],
        'Security' => [],
    ];

    /**
     * Creates a release with normalized category entries.
     *
     * @param array<string, list<string>> $entries entries keyed by category value
     */
    public function __construct(
        private string $version,
        private ?string $date = null,
        array $entries = [],
    ) {
        foreach ($this->entries as $category => $categoryEntries) {
            $this->entries[$category] = array_values(array_unique($entries[$category] ?? $categoryEntries));
        }
    }

    /**
     * Returns the release version exactly as stored in the document.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Returns the release date, or null when no date was declared.
     */
    public function getDate(): ?string
    {
        return $this->date;
    }

    /**
     * Determines whether this release is the mutable Unreleased section.
     */
    public function isUnreleased(): bool
    {
        return ChangelogDocument::UNRELEASED_VERSION === $this->version;
    }

    /**
     * Returns all entries grouped by canonical category value.
     *
     * @return array<string, list<string>>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * Returns entries for one category.
     *
     * @return list<string>
     */
    public function getEntriesFor(ChangelogEntryType $type): array
    {
        return $this->entries[$type->value];
    }

    /**
     * Determines whether at least one category contains an entry.
     */
    public function hasEntries(): bool
    {
        foreach ($this->entries as $entries) {
            if ([] !== $entries) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a release containing the additional normalized entry.
     *
     * Empty entries MUST be ignored and duplicates MUST NOT be added.
     */
    public function withEntry(ChangelogEntryType $type, string $entry): self
    {
        $entry = trim($entry);

        if ('' === $entry) {
            return $this;
        }

        $release = clone $this;
        $release->entries[$type->value][] = $entry;
        $release->entries[$type->value] = array_values(array_unique($release->entries[$type->value]));

        return $release;
    }

    /**
     * Returns a release with all category entries replaced and normalized.
     *
     * @param array<string, list<string>> $entries entries keyed by category value
     */
    public function withEntries(array $entries): self
    {
        $release = clone $this;

        foreach (array_keys($release->entries) as $category) {
            $release->entries[$category] = array_values(array_unique($entries[$category] ?? []));
        }

        return $release;
    }

    /**
     * Returns a release with the supplied date.
     */
    public function withDate(?string $date): self
    {
        $release = clone $this;
        $release->date = $date;

        return $release;
    }
}
