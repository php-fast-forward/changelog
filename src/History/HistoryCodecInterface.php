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

use FastForward\Changelog\Template\TemplateInterface;

/** Parses and renders the sole changelog history without translating entry descriptions. */
interface HistoryCodecInterface
{
    /** Parses supported release boundaries while retaining all body and footer bytes. */
    public function parse(string $markdown): HistoryDocument;

    /** Renders localized structure, or preserves existing presentation for incremental collection. */
    public function render(HistoryDocument $document, TemplateInterface $template, bool $preservePresentation = false): string;

    /** Returns the named release body without its heading or external reference footer. */
    public function notes(HistoryDocument $document, string $version): string;
}
