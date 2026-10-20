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

namespace FastForward\Changelog\Markdown\Parser;

use League\CommonMark\Extension\CommonMark\Parser\Block\FencedCodeStartParser;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/** Delegates fence grammar to CommonMark while recording explicit closing delimiters. */
final class TrackedFencedCodeStartParser implements BlockStartParserInterface
{
    /**
     * Wraps only fences accepted by the upstream parser; source positions and grammar remain upstream-owned.
     *
     * @param  Cursor                       $cursor      Current source cursor, advanced only by the delegated parser.
     * @param  MarkdownParserStateInterface $parserState Existing CommonMark container and paragraph context.
     * @return BlockStart|null              Decorated fenced-block parsers, or null when the line does not open a fence.
     */
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        $start = new FencedCodeStartParser()->tryStart($cursor, $parserState);

        if (null === $start) {
            return null;
        }
        $parsers = [];

        foreach ($start->getBlockParsers() as $parser) {
            $parsers[] = new TrackedFencedCodeContinueParser($parser);
        }

        return BlockStart::of(...$parsers)->at($cursor);
    }
}
