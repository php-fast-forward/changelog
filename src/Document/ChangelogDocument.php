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

final readonly class ChangelogDocument
{
    public const string UNRELEASED_VERSION = 'Unreleased';

    /**
     * @param list<ChangelogRelease> $releases
     */
    public function __construct(
        private array $releases,
    ) {}

    public static function create(): self
    {
        return new self([new ChangelogRelease(self::UNRELEASED_VERSION)]);
    }

    /**
     * @return list<ChangelogRelease>
     */
    public function getReleases(): array
    {
        return $this->releases;
    }

    public function getUnreleased(): ChangelogRelease
    {
        foreach ($this->releases as $release) {
            if ($release->isUnreleased()) {
                return $release;
            }
        }

        return new ChangelogRelease(self::UNRELEASED_VERSION);
    }

    public function getRelease(string $version): ?ChangelogRelease
    {
        foreach ($this->releases as $release) {
            if ($release->getVersion() === $version) {
                return $release;
            }
        }

        return null;
    }

    public function getLatestPublishedRelease(): ?ChangelogRelease
    {
        foreach ($this->releases as $release) {
            if (! $release->isUnreleased()) {
                return $release;
            }
        }

        return null;
    }

    public function withRelease(ChangelogRelease $target): self
    {
        $releases = [];
        $replaced = false;

        foreach ($this->releases as $release) {
            if ($release->getVersion() === $target->getVersion()) {
                $releases[] = $target;
                $replaced = true;

                continue;
            }

            $releases[] = $release;
        }

        if (! $replaced) {
            if ($target->isUnreleased()) {
                array_unshift($releases, $target);
            } else {
                $inserted = false;

                foreach ($releases as $index => $release) {
                    if ($release->isUnreleased()) {
                        continue;
                    }

                    if ($this->shouldInsertBeforePublishedRelease($target, $release)) {
                        array_splice($releases, $index, 0, [$target]);
                        $inserted = true;

                        break;
                    }
                }

                if (! $inserted) {
                    $releases[] = $target;
                }
            }
        }

        return new self($this->normalizeUnreleasedPosition($releases));
    }

    public function promoteUnreleased(string $version, string $date): self
    {
        $unreleased = $this->getUnreleased();
        $promoted = new ChangelogRelease($version, $date, $unreleased->getEntries());
        $currentVersion = $this->getRelease($version);

        if ($currentVersion instanceof ChangelogRelease) {
            $mergedEntries = $currentVersion->getEntries();

            foreach (ChangelogEntryType::ordered() as $type) {
                $mergedEntries[$type->value] = array_values(array_unique([
                    ...$currentVersion->getEntriesFor($type),
                    ...$unreleased->getEntriesFor($type),
                ]));
            }

            $promoted = new ChangelogRelease($version, $date, $mergedEntries);
        }

        $releases = [];

        foreach ($this->releases as $release) {
            if ($release->isUnreleased()) {
                $releases[] = new ChangelogRelease(self::UNRELEASED_VERSION);
                $releases[] = $promoted;

                continue;
            }

            if ($release->getVersion() === $version) {
                continue;
            }

            $releases[] = $release;
        }

        if ([] === $releases) {
            $releases = [new ChangelogRelease(self::UNRELEASED_VERSION), $promoted];
        }

        return new self($this->normalizeUnreleasedPosition($releases));
    }

    /**
     * @param list<ChangelogRelease> $releases
     *
     * @return list<ChangelogRelease>
     */
    private function normalizeUnreleasedPosition(array $releases): array
    {
        $unreleased = null;
        $published = [];

        foreach ($releases as $release) {
            if ($release->isUnreleased()) {
                $unreleased ??= $release;

                continue;
            }

            $published[] = $release;
        }

        return [$unreleased ?? new ChangelogRelease(self::UNRELEASED_VERSION), ...$published];
    }

    private function shouldInsertBeforePublishedRelease(ChangelogRelease $target, ChangelogRelease $release): bool
    {
        $targetVersion = ltrim($target->getVersion(), 'vV');
        $releaseVersion = ltrim($release->getVersion(), 'vV');

        if (
            1 === preg_match('/^\d+(?:\.\d+)*(?:[-+][A-Za-z0-9\-.]+)?$/', $targetVersion)
            && 1 === preg_match('/^\d+(?:\.\d+)*(?:[-+][A-Za-z0-9\-.]+)?$/', $releaseVersion)
        ) {
            return version_compare($targetVersion, $releaseVersion, '>');
        }

        if (null !== $target->getDate() && null !== $release->getDate()) {
            return $target->getDate() > $release->getDate();
        }

        return false;
    }
}
