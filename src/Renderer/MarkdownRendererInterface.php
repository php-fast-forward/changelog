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

namespace FastForward\Changelog\Renderer;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;

/**
 * Defines deterministic Markdown rendering for documents and release bodies.
 */
interface MarkdownRendererInterface
{
    /**
     * Renders a complete changelog document.
     */
    public function render(ChangelogDocument $document, ?string $repositoryUrl = null): string;

    /**
     * Renders one release body without its version heading.
     */
    public function renderReleaseBody(ChangelogRelease $release): string;
}
