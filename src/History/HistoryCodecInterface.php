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
    /**
     * Parses ordinary or legacy marked history; the selected template recognizes custom headings.
     *
     * @param string                 $markdown Complete central-history Markdown, including its introduction and reference footer.
     * @param TemplateInterface|null $template Optional selected template used to recognize custom headings alongside built-in syntax.
     *
     * @return HistoryDocument Parsed history with lossless release bodies, introductory Markdown and reference footer.
     */
    public function parse(string $markdown, ?TemplateInterface $template = null): HistoryDocument;

    /**
     * Renders localized structure, or preserves existing presentation for incremental collection.
     *
     * @param HistoryDocument   $document             Maintained history whose release bodies, introduction and references must be preserved.
     * @param TemplateInterface $template             Validated structural presentation used to recognize or render history headings.
     * @param bool              $preservePresentation Whether incremental rendering must retain existing headings and framing.
     *
     * @return string Complete Markdown history with selected structural headings and preserved release content.
     */
    public function render(
        HistoryDocument $document,
        TemplateInterface $template,
        bool $preservePresentation = false,
    ): string;

    /**
     * Returns the named release body without its heading or external reference footer.
     *
     * @param HistoryDocument $document Maintained history whose release bodies, introduction and references must be preserved.
     * @param string          $version  Canonical release identity to render or select; lookup accepts one conventional v prefix.
     *
     * @return string Exact selected release body without its outer heading or document reference footer.
     */
    public function notes(HistoryDocument $document, string $version): string;
}
