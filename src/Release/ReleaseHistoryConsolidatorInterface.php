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

namespace FastForward\Changelog\Release;

use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\Template\TemplateInterface;

/** Defines the same legacy-pending promotion for planning and committed publication proof. */
interface ReleaseHistoryConsolidatorInterface
{
    /**
     * Promotes legacy pending notes and new fragments into one version, retaining prior releases and references.
     *
     * @param HistoryDocument   $document Maintained history whose release bodies, introduction and references must be preserved.
     * @param string            $version  Concrete semantic version receiving the combined legacy pending descriptions and new fragment notes.
     * @param string|null       $date     Observed calendar date in YYYY-MM-DD form, or null when unavailable.
     * @param string            $notes    Exact rendered body of the selected new release, without its outer heading.
     * @param TemplateInterface $template Validated structural presentation used to recognize or render history headings.
     *
     * @return HistoryDocument History containing the concrete release with legacy pending notes promoted exactly once.
     */
    public function promote(
        HistoryDocument $document,
        string $version,
        ?string $date,
        string $notes,
        TemplateInterface $template,
    ): HistoryDocument;
}
