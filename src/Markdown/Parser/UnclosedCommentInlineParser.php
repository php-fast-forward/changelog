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

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/** Records otherwise unclaimed comment openers after upstream inline HTML has had priority. */
final class UnclosedCommentInlineParser implements InlineParserInterface
{
    public const string UNCLOSED_COMMENT = 'fast_forward.unclosed_comment';

    /**
     * Observes only raw openers reached by the inline pipeline, after code, escapes and complete HTML.
     *
     * @return InlineParserMatch The literal opener; link titles and HTML attributes remain owned by upstream parsers.
     */
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::string('<!--');
    }

    /**
     * Marks the owning block without consuming source or changing the upstream inline tree.
     *
     * Complete comments are already consumed by the higher-priority core HTML parser. Returning false leaves
     * an unmatched opener as literal text while exposing the stricter fragment-safety evidence separately.
     *
     * @param  InlineParserContext $inlineContext Cursor and owning block supplied by the upstream inline pipeline.
     * @return bool                Always false so the ordinary text fallback preserves the original Markdown interpretation.
     */
    public function parse(InlineParserContext $inlineContext): bool
    {
        $inlineContext->getContainer()->data->set(self::UNCLOSED_COMMENT, true);

        return false;
    }
}
