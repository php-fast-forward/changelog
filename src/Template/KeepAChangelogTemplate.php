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
     * @param array<string,string> $categoryHeadings headings keyed by canonical category
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

    /** Returns the configured locale without consulting the process environment. */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /** Returns the configured introductory Markdown exactly as stored. */
    public function introduction(): string
    {
        return $this->introduction;
    }

    /** Substitutes the version and date into the validated structural heading. */
    public function releaseHeading(string $version, ?string $date): string
    {
        return strtr(null === $date ? $this->releaseHeading : $this->datedReleaseHeading, [
            '{version}' => $version,
            '{date}' => $date ?? '',
        ]);
    }

    /** Returns a heading after the category enum has validated the identifier. */
    public function categoryHeading(string $category): string
    {
        return $this->categoryHeadings[Category::from($category)->value];
    }

    /** Returns the configured pending-changes heading. */
    public function unreleasedHeading(): string
    {
        return $this->unreleasedHeading;
    }

    /** Returns the localized statement of missing historical notes. */
    public function missingNotes(): string
    {
        return $this->missingNotes;
    }
}
