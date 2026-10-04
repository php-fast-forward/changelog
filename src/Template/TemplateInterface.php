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
    /** Returns the selected locale identifier. */
    public function getLocale(): string;

    /** Returns the introductory Markdown, without an imposed trailing newline. */
    public function introduction(): string;

    /** Returns one release heading using a canonical version and optional date. */
    public function releaseHeading(string $version, ?string $date): string;

    /** Returns the structural heading for one canonical lowercase category. */
    public function categoryHeading(string $category): string;

    /** Returns the localized heading for pending changes. */
    public function unreleasedHeading(): string;

    /** Describes an imported historical version for which no notes were supplied. */
    public function missingNotes(): string;
}
