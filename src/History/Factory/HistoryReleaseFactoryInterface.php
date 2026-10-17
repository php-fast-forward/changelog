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

namespace FastForward\Changelog\History\Factory;

use FastForward\Changelog\History\HistoryRelease;

/** Constructs lossless historical release values with explicit date provenance. */
interface HistoryReleaseFactoryInterface
{
    /**
     * Creates a strict semantic section, preserving all raw presentation fields.
     *
     * @param string      $version    Semantic release identity, with the reserved unreleased value permitted only for pending history.
     * @param string|null $date       Observed calendar date in YYYY-MM-DD form, or null when unavailable.
     * @param string|null $dateSource Declared date provenance, or null when no source is known.
     * @param string      $body       Raw release body bytes, preserving Markdown whitespace and code fences.
     * @param string|null $heading    Original release heading and marker bytes, or null when a new section will use the selected template.
     * @param string      $ending     Original structural end marker and following spacing retained for lossless parsing.
     *
     * @return HistoryRelease Validated semantic section retaining raw body, heading, ending and known date provenance.
     *
     * @throws \InvalidArgumentException When the identity is neither strict SemVer nor unreleased, or a supplied calendar date is invalid.
     */
    public function create(
        string $version,
        ?string $date = null,
        ?string $dateSource = null,
        string $body = '',
        ?string $heading = null,
        string $ending = '',
    ): HistoryRelease;
}
