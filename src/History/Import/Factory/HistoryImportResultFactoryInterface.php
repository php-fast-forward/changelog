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

namespace FastForward\Changelog\History\Import\Factory;

use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\Import\HistoryImportResult;

/** Isolates typed import-result construction from the importer service. */
interface HistoryImportResultFactoryInterface
{
    /**
     * Constructs a result from preserved history and a caller-validated stable tag baseline.
     * @param list<string> $missingVersions canonical identities inserted during import
     */
    public function create(HistoryDocument $document, array $missingVersions, string $currentVersion): HistoryImportResult;
}
