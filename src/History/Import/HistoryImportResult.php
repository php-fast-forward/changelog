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

namespace FastForward\Changelog\History\Import;

use FastForward\Changelog\History\HistoryDocument;

/** Retains imported history and the tag-derived baseline used for release planning. */
final readonly class HistoryImportResult
{
    /**
     * Stores complete import evidence without changing existing release values.
     *
     * @param HistoryDocument $document        Maintained history whose release bodies, introduction and references must be preserved.
     * @param list<string>    $missingVersions only versions newly inserted by this import
     * @param string          $currentVersion  Validated current stable version used as the numeric release baseline.
     */
    public function __construct(
        public HistoryDocument $document,
        public array $missingVersions,
        public string $currentVersion,
    ) {}
}
