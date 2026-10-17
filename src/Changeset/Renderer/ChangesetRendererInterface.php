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

namespace FastForward\Changelog\Changeset\Renderer;

use FastForward\Changelog\Changeset\Changeset;

/**
 * Renders one validated changeset into canonical Markdown.
 */
interface ChangesetRendererInterface
{
    /**
     * Produces deterministic frontmatter and preserves the validated body.
     *
     * @param Changeset $changeset Validated fragment, including its canonical filename and persisted effective impact.
     *
     * @return string Canonical scalar frontmatter and unchanged description with one final newline.
     */
    public function render(Changeset $changeset): string;
}
