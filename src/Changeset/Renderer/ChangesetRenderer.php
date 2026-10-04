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
 * Serializes one fragment with a stable schema and its materialized impact.
 */
final readonly class ChangesetRenderer implements ChangesetRendererInterface
{
    /**
     * Emits category and type first, omitting unavailable optional metadata.
     *
     * The Markdown description remains byte-identical and output ends with a
     * newline. Legacy metadata names MUST never be written.
     */
    public function render(Changeset $changeset): string
    {
        $lines = [
            '---',
            sprintf('category: %s', $changeset->category->value),
            sprintf('type: %s', $changeset->type->value),
        ];

        if (null !== $changeset->issue) {
            $lines[] = sprintf('issue: %d', $changeset->issue);
        }

        if (null !== $changeset->pullRequest) {
            $lines[] = sprintf('pull_request: %d', $changeset->pullRequest);
        }

        if (null !== $changeset->author) {
            $lines[] = sprintf('author: "%s"', $changeset->author);
        }

        return implode("\n", [...$lines, '---', '', $changeset->description]) . "\n";
    }
}
