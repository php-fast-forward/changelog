<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Release\Renderer;

use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Template\TemplateInterface;

/** Renders fragment descriptions and retained metadata into the new release body. */
interface ReleaseNotesRendererInterface
{
    /**
     * Groups changes deterministically without translating author-written descriptions.
     * @param list<Changeset> $changesets validated fragments
     */
    public function render(array $changesets, TemplateInterface $template, ?string $repository = null): string;
}
