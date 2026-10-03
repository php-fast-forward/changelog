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
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\TemplateInterface;

/** Imports missing sections from actual stable Git tags and optional published release notes. */
interface HistoryImporterInterface
{
    /**
     * Preserves existing sections and returns the highest matching Git version, or 0.0.0.
     * @param list<array{name:string,sha:string,date:?string,date_source:?string}> $tags explicit Git evidence
     */
    public function import(HistoryDocument $document, ReleaseOptions $options, TemplateInterface $template, array $tags): HistoryImportResult;

    /**
     * Resolves a stable tag baseline with the same import policy, without accessing GitHub.
     * @param list<array{name:string,sha:string,date:?string,date_source:?string}> $tags explicit Git evidence
     */
    public function currentVersion(array $tags, string $tagPrefix = 'v'): string;
}
