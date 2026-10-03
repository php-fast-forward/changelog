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
    /** Stores the validated semantic identity and lossless presentation fragments. */
    public function __construct(
        private string $version,
        private ?string $date = null,
        private ?string $dateSource = null,
        private string $body = '',
        private ?string $heading = null,
        private string $ending = '',
    ) {}

    /** Returns the canonical semantic version, or unreleased for pending notes. */
    public function getVersion(): string
    {
        return $this->version;
    }

    /** Returns the observed date without inventing one for undated history. */
    public function getDate(): ?string
    {
        return $this->date;
    }

    /** Returns the declared date source, or null when provenance is unknown. */
    public function getDateSource(): ?string
    {
        return $this->dateSource;
    }

    /** Returns the raw body bytes, including meaningful whitespace and code blocks. */
    public function getBody(): string
    {
        return $this->body;
    }

    /** Returns the original marker and heading lines, or null for a new section. */
    public function getHeading(): ?string
    {
        return $this->heading;
    }

    /** Returns an optional structural end marker and preserved following spacing. */
    public function getEnding(): string
    {
        return $this->ending;
    }
}
