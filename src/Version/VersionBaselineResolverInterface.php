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

/** Separates the maintained version baseline from evidence that a version was published. */
interface VersionBaselineResolverInterface
{
    /**
     * Selects the greatest stable identity without reading tags, publishing or changing history.
     *
     * @param HistoryDocument $history    Validated history from the selected source base; pending and prerelease sections do not establish a stable baseline.
     * @param string          $tagVersion Highest stable version from ancestral tags, or the existing 0.0.0 empty-history sentinel.
     *
     * @return string Greatest supported stable version, using arbitrary-width numeric precedence and deterministic build-metadata ties.
     *
     * @throws InvalidArgumentException When the supplied tag baseline is not a strict stable semantic version.
     */
    public function resolve(HistoryDocument $history, string $tagVersion): string;
}
