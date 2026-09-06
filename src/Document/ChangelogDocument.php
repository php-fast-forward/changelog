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

use function Safe\preg_match;

/**
 * Stores an immutable, newest-first sequence of changelog releases.
 *
 * The Unreleased section MUST remain first. Published releases MUST retain
 * semantic ordering when their versions are comparable, with dates used as a
 * fallback for non-semantic labels.
 */
final class ChangelogDocument
{
    public const string UNRELEASED_VERSION = 'Unreleased';

    /**
     * Initializes a document from releases already ordered by the caller.
     *
     * @param list<ChangelogRelease> $releases
     * @param list<string> $references Markdown reference definitions retained from input
     */
    public function __construct(
        private array $releases,
        private array $references = [],
    ) {}

    /**
     * Returns every release in rendering order.
     *
     * @return list<ChangelogRelease>
     */
    public function getReleases(): array
    {
        return $this->releases;
    }

    /**
     * Returns the original Markdown reference definitions in source order.
     *
     * These definitions MUST remain available when repository discovery cannot
     * supply a replacement URL during a later mutation.
     *
     * @return list<string>
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * Returns the required Unreleased section.
     *
     * @throws \LogicException when the document invariant was violated
     */
    public function getUnreleased(): ChangelogRelease
    {
        foreach ($this->releases as $release) {
            if ($release->isUnreleased()) {
                return $release;
            }
        }

        throw new \LogicException('A changelog document MUST contain an Unreleased section.');
    }

    /**
     * Returns a release by semantic identity, ignoring an optional v prefix.
     */
    public function getRelease(string $version): ?ChangelogRelease
    {
        foreach ($this->releases as $release) {
            if ($this->hasSameVersionIdentity($release->getVersion(), $version)) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Returns the newest published release, if one exists.
     */
    public function getLatestPublishedRelease(): ?ChangelogRelease
    {
        foreach ($this->releases as $release) {
            if (! $release->isUnreleased()) {
                return $release;
            }
        }

        return null;
    }

    /**
     * Returns a document with the target release inserted or replaced.
     *
     * The method MUST keep Unreleased first and MUST order comparable published
     * versions from newest to oldest.
     */
    public function withRelease(ChangelogRelease $target): self
    {
        $releases = [];
        $replaced = false;

        foreach ($this->releases as $release) {
            if ($this->hasSameVersionIdentity($release->getVersion(), $target->getVersion())) {
                $releases[] = $release
                    ->withEntries($target->getEntries())
                    ->withDate($target->getDate());
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

        $document = clone $this;
        $document->releases = $this->normalizeUnreleasedPosition($releases);

        return $document;
    }

    /**
     * Replaces Unreleased with an empty section and inserts a promoted release.
     *
     * Existing entries for the same version MUST be merged without duplicates.
     */
    public function promoteUnreleased(
        ChangelogRelease $promoted,
        ChangelogRelease $emptyUnreleased,
    ): self
    {
        $unreleased = $this->getUnreleased();
        $version = $promoted->getVersion();
        $currentVersion = $this->getRelease($version);

        if ($currentVersion instanceof ChangelogRelease) {
            $mergedEntries = $currentVersion->getEntries();

            foreach ($unreleased->getEntries() as $category => $entries) {
                $mergedEntries[$category] = array_values(array_unique([
                    ...($mergedEntries[$category] ?? []),
                    ...$entries,
                ]));
            }

            $promoted = $currentVersion
                ->withEntries($mergedEntries)
                ->withDate($promoted->getDate());
        }

        $releases = [];

        foreach ($this->releases as $release) {
            if ($release->isUnreleased()) {
                $releases[] = $emptyUnreleased;

                continue;
            }

            if ($this->hasSameVersionIdentity($release->getVersion(), $version)) {
                continue;
            }

            $releases[] = $release;
        }

        $document = clone $this;
        $document->releases = $this->normalizeUnreleasedPosition($releases);

        return $document->withRelease($promoted);
    }

    /**
     * Moves the first Unreleased release to index zero and discards duplicates.
     *
     * The result MUST contain exactly one Unreleased release.
     *
     * @param list<ChangelogRelease> $releases
     *
     * @return list<ChangelogRelease>
     *
     * @throws \LogicException when the supplied releases omit Unreleased
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

        if (! $unreleased instanceof ChangelogRelease) {
            throw new \LogicException('A changelog document MUST contain an Unreleased section.');
        }

        return [$unreleased, ...$published];
    }

    /**
     * Determines whether a published release belongs before another release.
     */
    private function shouldInsertBeforePublishedRelease(ChangelogRelease $target, ChangelogRelease $release): bool
    {
        $targetVersion = $this->parseSemanticVersion($target->getVersion());
        $releaseVersion = $this->parseSemanticVersion($release->getVersion());

        if (null !== $targetVersion && null !== $releaseVersion) {
            return 0 < $this->compareSemanticVersions($targetVersion, $releaseVersion);
        }

        if (null !== $target->getDate() && null !== $release->getDate()) {
            return $target->getDate() > $release->getDate();
        }

        return false;
    }

    /**
     * Determines whether two labels identify the same version.
     */
    private function hasSameVersionIdentity(string $first, string $second): bool
    {
        return $this->normalizeVersionPrefix($first) === $this->normalizeVersionPrefix($second);
    }

    /**
     * Removes one conventional v prefix while preserving every other label byte.
     */
    private function normalizeVersionPrefix(string $version): string
    {
        if (1 === preg_match('/^[vV](?=\d)/', $version)) {
            return substr($version, 1);
        }

        return $version;
    }

    /**
     * Parses the precedence-bearing fields defined by Semantic Versioning 2.0.
     *
     * Build metadata MUST NOT participate in precedence.
     *
     * @return array{core: list<string>, prerelease: list<string>|null}|null
     */
    private function parseSemanticVersion(string $version): ?array
    {
        $version = $this->normalizeVersionPrefix($version);

        if (1 !== preg_match(
            '/^(?<major>0|[1-9]\d*)\.(?<minor>0|[1-9]\d*)\.(?<patch>0|[1-9]\d*)'
            . '(?:-(?<prerelease>[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/',
            $version,
            $matches,
        )) {
            return null;
        }

        $prerelease = '' === ($matches['prerelease'] ?? '')
            ? null
            : explode('.', $matches['prerelease']);

        if (null !== $prerelease) {
            foreach ($prerelease as $identifier) {
                if (ctype_digit($identifier) && 1 < \strlen($identifier) && '0' === $identifier[0]) {
                    return null;
                }
            }
        }

        return [
            'core' => [$matches['major'], $matches['minor'], $matches['patch']],
            'prerelease' => $prerelease,
        ];
    }

    /**
     * Compares parsed semantic versions according to SemVer 2.0 precedence.
     *
     * @param array{core: list<string>, prerelease: list<string>|null} $first
     * @param array{core: list<string>, prerelease: list<string>|null} $second
     */
    private function compareSemanticVersions(array $first, array $second): int
    {
        foreach ($first['core'] as $index => $identifier) {
            $comparison = $this->compareNumericIdentifiers($identifier, $second['core'][$index]);

            if (0 !== $comparison) {
                return $comparison;
            }
        }

        return $this->comparePrereleaseIdentifiers($first['prerelease'], $second['prerelease']);
    }

    /**
     * Compares prerelease sequences where numeric identifiers have lower precedence.
     *
     * @param list<string>|null $first
     * @param list<string>|null $second
     */
    private function comparePrereleaseIdentifiers(?array $first, ?array $second): int
    {
        if (null === $first) {
            return null === $second ? 0 : 1;
        }

        if (null === $second) {
            return -1;
        }

        $identifierCount = max(\count($first), \count($second));

        for ($index = 0; $index < $identifierCount; ++$index) {
            if (! isset($first[$index])) {
                return -1;
            }

            if (! isset($second[$index])) {
                return 1;
            }

            if ($first[$index] === $second[$index]) {
                continue;
            }

            $firstIsNumeric = ctype_digit($first[$index]);
            $secondIsNumeric = ctype_digit($second[$index]);

            if ($firstIsNumeric && $secondIsNumeric) {
                return $this->compareNumericIdentifiers($first[$index], $second[$index]);
            }

            if ($firstIsNumeric !== $secondIsNumeric) {
                return $firstIsNumeric ? -1 : 1;
            }

            return strcmp($first[$index], $second[$index]) <=> 0;
        }

        return 0;
    }

    /**
     * Compares arbitrary-length non-negative integers without numeric overflow.
     */
    private function compareNumericIdentifiers(string $first, string $second): int
    {
        $lengthComparison = \strlen($first) <=> \strlen($second);

        return 0 !== $lengthComparison ? $lengthComparison : strcmp($first, $second) <=> 0;
    }
}
