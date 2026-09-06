<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://github.com/php-fast-forward/changelog/issues
 * @see       https://php-fast-forward.github.io/changelog/
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Manager;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Entry\ChangelogEntryType;

/**
 * Defines the application-level changelog operations exposed to commands.
 */
interface ChangelogManagerInterface
{
    /**
     * Adds a categorized entry to a release section.
     */
    public function addEntry(
        string $file,
        ChangelogEntryType $type,
        string $message,
        string $version = ChangelogDocument::UNRELEASED_VERSION,
        ?string $date = null,
    ): void;

    /**
     * Promotes Unreleased entries into a published release.
     */
    public function promote(string $file, string $version, string $date): void;

    /**
     * Infers the next semantic version for a changelog.
     */
    public function inferNextVersion(string $file, ?string $currentVersion = null): string;

    /**
     * Renders one published release body.
     */
    public function renderReleaseNotes(string $file, string $version): string;

    /**
     * Loads or initializes a changelog document.
     */
    public function load(string $file): ChangelogDocument;
}
