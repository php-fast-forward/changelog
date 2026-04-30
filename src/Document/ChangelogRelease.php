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

namespace FastForward\Changelog\Document;

use FastForward\Changelog\Entry\ChangelogEntryType;

final class ChangelogRelease
{
    /**
     * @var array<string, list<string>>
     */
    private array $entries = [];

    /**
     * @param array<string, list<string>> $entries
     */
    public function __construct(
        private readonly string $version,
        private readonly ?string $date = null,
        array $entries = [],
    ) {
        foreach (ChangelogEntryType::ordered() as $type) {
            $this->entries[$type->value] = array_values(array_unique($entries[$type->value] ?? []));
        }
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    public function isUnreleased(): bool
    {
        return ChangelogDocument::UNRELEASED_VERSION === $this->version;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function getEntriesFor(ChangelogEntryType $type): array
    {
        return $this->entries[$type->value];
    }

    public function hasEntries(): bool
    {
        foreach ($this->entries as $entries) {
            if ([] !== $entries) {
                return true;
            }
        }

        return false;
    }

    public function withEntry(ChangelogEntryType $type, string $entry): self
    {
        $entries = $this->entries;
        $entry = trim($entry);

        if ('' === $entry) {
            return $this;
        }

        $entries[$type->value][] = $entry;
        $entries[$type->value] = array_values(array_unique($entries[$type->value]));

        return new self($this->version, $this->date, $entries);
    }

    /**
     * @param array<string, list<string>> $entries
     */
    public function withEntries(array $entries): self
    {
        return new self($this->version, $this->date, $entries);
    }

    public function withDate(?string $date): self
    {
        return new self($this->version, $date, $this->entries);
    }
}
