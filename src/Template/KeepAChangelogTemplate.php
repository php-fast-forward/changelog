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

namespace FastForward\Changelog\Template;

use FastForward\Changelog\Changeset\Category;

/** Stores validated localized headings and introduction text as an immutable presentation. */
final readonly class KeepAChangelogTemplate implements TemplateInterface
{
    /**
     * Stores the complete validated presentation supplied by its factory.
     *
     * @param string               $locale              Supported structural language identifier, en or pt-BR.
     * @param string               $introduction        Raw introductory Markdown preceding release sections.
     * @param string               $releaseHeading      Validated level-two heading pattern containing the version placeholder.
     * @param string               $datedReleaseHeading Validated level-two heading pattern containing both version and date placeholders.
     * @param array<string,string> $categoryHeadings    headings keyed by canonical category
     * @param string               $unreleasedHeading   Validated localized heading used to recognize legacy pending descriptions.
     * @param string               $missingNotes        Localized message used when a tagged historical version has no published notes.
     */
    public function __construct(
        private string $locale,
        private string $introduction,
        private string $releaseHeading,
        private string $datedReleaseHeading,
        private array $categoryHeadings,
        private string $unreleasedHeading,
        private string $missingNotes,
    ) {}

    /**
     * Returns the configured locale without consulting the process environment.
     *
     * @return string Selected structural-language identifier without consulting host locale settings.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Returns the configured introductory Markdown exactly as stored.
     *
     * @return string Configured introductory Markdown without an imposed trailing newline.
     */
    public function introduction(): string
    {
        return $this->introduction;
    }

    /**
     * Substitutes the version and date into the validated structural heading.
     *
     * @param string      $version Canonical release identity substituted into the validated heading pattern.
     * @param string|null $date    Observed calendar date in YYYY-MM-DD form, or null when unavailable.
     *
     * @return string Validated structural heading with the supplied version and optional date substituted.
     */
    public function releaseHeading(string $version, ?string $date): string
    {
        return strtr(null === $date ? $this->releaseHeading : $this->datedReleaseHeading, [
            '{version}' => $version,
            '{date}' => $date ?? '',
        ]);
    }

    /**
     * Returns a heading after the category enum has validated the identifier.
     *
     * @param string $category Canonical lowercase Keep a Changelog category identifier.
     *
     * @return string Structural level-three heading for the canonical category identifier.
     */
    public function categoryHeading(string $category): string
    {
        return $this->categoryHeadings[Category::from($category)->value];
    }

    /**
     * Returns the configured pending-changes heading.
     *
     * @return string Configured localized heading recognizing legacy pending descriptions.
     */
    public function unreleasedHeading(): string
    {
        return $this->unreleasedHeading;
    }

    /**
     * Returns the localized statement of missing historical notes.
     *
     * @return string Localized placeholder for a tagged version lacking published notes.
     */
    public function missingNotes(): string
    {
        return $this->missingNotes;
    }
}
