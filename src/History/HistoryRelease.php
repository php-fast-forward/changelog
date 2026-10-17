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

/** Retains one release's identity, known date provenance and exact Markdown body. */
final readonly class HistoryRelease
{
    /**
     * Stores the validated semantic identity and lossless presentation fragments.
     *
     * @param string      $version    Semantic release identity, with the reserved unreleased value permitted only for pending history.
     * @param string|null $date       Observed calendar date in YYYY-MM-DD form, or null when unavailable.
     * @param string|null $dateSource Declared date provenance, or null when no source is known.
     * @param string      $body       Raw release body bytes, preserving Markdown whitespace and code fences.
     * @param string|null $heading    Original release heading and marker bytes, or null when a new section will use the selected template.
     * @param string      $ending     Original structural end marker and following spacing retained for lossless parsing.
     */
    public function __construct(
        private string $version,
        private ?string $date = null,
        private ?string $dateSource = null,
        private string $body = '',
        private ?string $heading = null,
        private string $ending = '',
    ) {}

    /**
     * Returns the canonical semantic version, or unreleased for pending notes.
     *
     * @return string Canonical semantic identity, or unreleased for pending descriptions.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Returns the observed date without inventing one for undated history.
     *
     * @return string|null Observed calendar date, or null when no publication date is known.
     */
    public function getDate(): ?string
    {
        return $this->date;
    }

    /**
     * Returns the declared date source, or null when provenance is unknown.
     *
     * @return string|null Declared date-provenance label, or null when unavailable.
     */
    public function getDateSource(): ?string
    {
        return $this->dateSource;
    }

    /**
     * Returns the raw body bytes, including meaningful whitespace and code blocks.
     *
     * @return string Raw maintained body bytes, including meaningful Markdown whitespace.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Returns the original marker and heading lines, or null for a new section.
     *
     * @return string|null Original section heading and markers, or null for a newly created section.
     */
    public function getHeading(): ?string
    {
        return $this->heading;
    }

    /**
     * Returns an optional structural end marker and preserved following spacing.
     *
     * @return string Preserved structural end marker and following spacing.
     */
    public function getEnding(): string
    {
        return $this->ending;
    }
}
