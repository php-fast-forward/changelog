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

namespace FastForward\Changelog\Version;

use FastForward\Changelog\History\HistoryDocument;
use InvalidArgumentException;

/** Resolves maintained version state independently of tag or GitHub Release publication. */
final readonly class VersionBaselineResolver implements VersionBaselineResolverInterface
{
    /**
     * Combines approved stable history and an already selected ancestral-tag baseline without I/O.
     *
     * @param HistoryDocument $history    Validated source history whose sections and Markdown are never modified.
     * @param string          $tagVersion Highest ancestral stable version, or 0.0.0 when no stable tag exists.
     *
     * @return string Greatest stable baseline; its selection does not assert that a tag or release exists.
     *
     * @throws InvalidArgumentException When the supplied tag baseline is not strict stable SemVer.
     */
    public function resolve(HistoryDocument $history, string $tagVersion): string
    {
        if (!$this->stable($tagVersion)) {
            throw new InvalidArgumentException('The tag baseline must be a strict stable semantic version.');
        }
        $current = $tagVersion;

        foreach ($history->getReleases() as $release) {
            $version = preg_replace('/^[vV](?=\d)/', '', $release->getVersion());

            if ($this->stable($version) && $this->compare($version, $current) > 0) {
                $current = $version;
            }
        }

        return $current;
    }

    /**
     * Recognizes stable semantic identities while excluding pending headings and prereleases.
     *
     * @param string $version Canonical history identity or caller-supplied tag baseline.
     *
     * @return bool Whether the identity has a strict three-component numeric core and optional build metadata.
     */
    private function stable(string $version): bool
    {
        return 1 === preg_match(
            '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/',
            $version,
        );
    }

    /**
     * Preserves numeric precision and the historical importer's deterministic build-metadata tie order.
     *
     * @param string $left  Validated candidate stable identity.
     * @param string $right Validated current stable identity.
     *
     * @return int Numeric SemVer ordering, followed by lexical identity ordering for equal numeric cores.
     */
    private function compare(string $left, string $right): int
    {
        $leftParts = explode('.', explode('+', $left, 2)[0]);
        $rightParts = explode('.', explode('+', $right, 2)[0]);

        foreach ($leftParts as $index => $component) {
            $comparison = strlen($component) <=> strlen($rightParts[$index]);

            if (0 === $comparison) {
                $comparison = strcmp($component, $rightParts[$index]);
            }

            if (0 !== $comparison) {
                return $comparison;
            }
        }

        return strcmp($left, $right);
    }
}
