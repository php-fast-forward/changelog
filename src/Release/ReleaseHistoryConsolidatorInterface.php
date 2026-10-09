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
    /** Promotes legacy pending notes and new fragments into one version, retaining prior releases and references. */
    public function promote(
        HistoryDocument $document,
        string $version,
        ?string $date,
        string $notes,
        TemplateInterface $template,
    ): HistoryDocument;
}
