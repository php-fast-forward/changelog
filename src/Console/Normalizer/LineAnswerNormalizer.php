<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Console\Normalizer;

/** Removes the terminal's Enter delimiter without trimming Markdown spaces or preceding blank lines. */
final readonly class LineAnswerNormalizer
{
    /** Preserves an absent answer; removes only one final LF or CRLF sequence from a captured line. */
    public function __invoke(?string $value): ?string
    {
        return null === $value ? null : preg_replace('/\r?\n\z/', '', $value);
    }
}
