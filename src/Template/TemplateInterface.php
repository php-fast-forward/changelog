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

/** Defines a localized Keep a Changelog presentation without translating descriptions. */
interface TemplateInterface
{
    /**
     * Returns the selected locale identifier.
     *
     * @return string Selected structural-language identifier without consulting host locale settings.
     */
    public function getLocale(): string;

    /**
     * Returns the introductory Markdown, without an imposed trailing newline.
     *
     * @return string Configured introductory Markdown without an imposed trailing newline.
     */
    public function introduction(): string;

    /**
     * Returns one release heading using a canonical version and optional date.
     *
     * @param string      $version Canonical release identity substituted into the validated heading pattern.
     * @param string|null $date    Observed calendar date in YYYY-MM-DD form, or null when unavailable.
     *
     * @return string Validated structural heading with the supplied version and optional date substituted.
     */
    public function releaseHeading(string $version, ?string $date): string;

    /**
     * Returns the structural heading for one canonical lowercase category.
     *
     * @param string $category Canonical lowercase Keep a Changelog category identifier.
     *
     * @return string Structural level-three heading for the canonical category identifier.
     */
    public function categoryHeading(string $category): string;

    /**
     * Returns the localized heading for pending changes.
     *
     * @return string Configured localized heading recognizing legacy pending descriptions.
     */
    public function unreleasedHeading(): string;

    /**
     * Describes an imported historical version for which no notes were supplied.
     *
     * @return string Localized placeholder for a tagged version lacking published notes.
     */
    public function missingNotes(): string;
}
