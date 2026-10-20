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

namespace FastForward\Changelog\Markdown;

/** Shares bounded list/fence recognition between fragment validation, rendering and historical boundaries. */
final class BlockStructure
{
    /**
     * Recognizes a list marker with up to three local leading columns, excluding thematic breaks.
     *
     * Tabs are expanded only for recognition. Five or more spaces after a marker leave the excess as
     * indented content, matching Markdown's one-column list padding in that case.
     *
     * @param  string                                                $line Original source line; returned columns never authorize rewriting its bytes.
     * @return array{indent:int,contentIndent:int,ordered:bool}|null Local container columns, or null for ordinary content.
     */
    public static function listItem(string $line): ?array
    {
        $line = self::expandTabs($line);

        if (1 === preg_match('/\A {0,3}(?:(?:\* *){3,}|(?:- *){3,}|(?:_ *){3,})\r?\n?\z/', $line)
            || 1 !== preg_match('/\A( {0,3})([-+*]|[0-9]{1,9}[.)])( +|(?=\r?\n?\z))/', $line, $matches)) {
            return null;
        }
        $padding = max(1, strlen($matches[3]));

        return [
            'indent' => strlen($matches[1]),
            'contentIndent' => strlen($matches[1] . $matches[2]) + ($padding > 4 ? 1 : $padding),
            'ordered' => ctype_digit($matches[2][0]),
        ];
    }

    /**
     * Reuses an authored list or adds the existing outer item for prose and standalone fenced blocks.
     *
     * @param  string $description Authored Markdown whose marker/content bytes remain unchanged after unwrapping.
     * @return string Effective release-entry Markdown, shared with validation so fence positions cannot diverge.
     */
    public static function wrapDescription(string $description): string
    {
        if (null !== self::listItem(explode("\n", $description, 2)[0])) {
            return $description;
        }
        $fenced = str_contains($description, "\n")
            && 1 === preg_match('/\A {0,3}(`{3,}|~{3,})([^\n]*)/', $description, $matches)
            && ('~' === $matches[1][0] || ! str_contains($matches[2], '`'));

        return ($fenced ? "-\n  " : '- ') . preg_replace('/\n(?!\n|\z)/', "\n  ", $description);
    }

    /**
     * Advances fenced-block and list-container state without interpreting or rewriting ordinary inline Markdown.
     *
     * Blank lines retain container context. A nonblank dedent ends a list-contained fence and is examined again
     * as ordinary content; following release headings cannot disappear inside the previous list's code block.
     * Explicit closing fences allow three columns beyond the complete content boundary, including initial indent.
     *
     * @param  string                                              $line       Original source line, optionally including its line ending.
     * @param  array{character:string,length:int,padding:int}|null $fence      Mutable active fenced block.
     * @param  list<int>                                           $containers Mutable outer-to-inner list-content column stack for continuation lines.
     * @return bool                                                Whether this line is ordinary content outside a fence; delimiter/code lines return false.
     */
    public static function outsideFence(string $line, ?array &$fence, array &$containers): bool
    {
        $line = self::expandTabs($line);
        $indent = strspn($line, ' ');
        $blank = '' === trim($line);

        if (null !== $fence) {
            $dedented = $fence['padding'] > 0 && ! $blank && $indent < $fence['padding'];

            if (! $dedented) {
                if ($indent <= 3 + $fence['padding'] && 1 === preg_match(
                    '/\A *' . preg_quote($fence['character'], '/') . '{' . $fence['length'] . ',} *(?:\r?\n|\z)/',
                    $line,
                )) {
                    $fence = null;
                }

                return false;
            }
            $fence = null;
        }

        if (! $blank) {
            while ([] !== $containers && $indent < array_last($containers)) {
                array_pop($containers);
            }
        }
        $base = [] === $containers ? 0 : array_last($containers);
        $item = self::listItem(substr($line, $base));

        if (null !== $item) {
            $containers[] = $base + $item['contentIndent'];
            $base = array_last($containers);
        }
        $content = substr($line, min($base, strlen($line)));

        if (1 === preg_match('/\A {0,3}(`{3,}|~{3,})([^\r\n]*)/', $content, $matches)
            && ('~' === $matches[1][0] || ! str_contains($matches[2], '`'))) {
            $fence = ['character' => $matches[1][0], 'length' => strlen($matches[1]), 'padding' => $base];

            return false;
        }

        return true;
    }

    /**
     * Detects an HTML comment left open outside fenced code and complete backtick spans.
     *
     * Comments retain precedence over fence-looking text until their closing delimiter. This is a safety check,
     * not an HTML parser: its purpose is preventing a later delivery-reference paragraph from being hidden.
     *
     * @param  string $description Authored Markdown inspected without changing its bytes.
     * @return bool   Whether an unclosed comment would swallow appended release-reference content.
     */
    public static function hasUnclosedComment(string $description): bool
    {
        $fence = null;
        $containers = [];
        $comment = false;
        $span = null;
        $offset = 0;

        foreach (explode("\n", $description) as $line) {
            $start = $offset;
            $offset += strlen($line) + 1;

            if (! $comment && null === $span && ! self::outsideFence($line, $fence, $containers)) {
                continue;
            }
            preg_match_all('/`+|<!--|-->/', $line, $delimiters, PREG_OFFSET_CAPTURE);

            foreach ($delimiters[0] as [$delimiter, $position]) {
                if ($comment) {
                    $comment = '-->' !== $delimiter;
                } elseif (null !== $span) {
                    if (str_repeat('`', $span) === $delimiter) {
                        $span = null;
                    }
                } elseif (! self::isEscaped($line, $position)) {
                    if ('<!--' === $delimiter) {
                        $comment = true;
                    } elseif ('`' === $delimiter[0] && self::closesSpan(
                        $description,
                        $start + $position + strlen($delimiter),
                        strlen($delimiter),
                    )) {
                        $span = strlen($delimiter);
                    }
                }
            }
        }

        return $comment;
    }

    /**
     * Tests escape parity before a delimiter; an even number of backslashes leaves it active.
     *
     * @param  string $line   Original source line containing the candidate delimiter.
     * @param  int    $offset Byte offset of its first character.
     * @return bool   Whether an odd consecutive backslash run escapes this delimiter.
     */
    private static function isEscaped(string $line, int $offset): bool
    {
        $slashes = 0;

        while ($offset > 0 && '\\' === $line[--$offset]) {
            ++$slashes;
        }

        return 1 === $slashes % 2;
    }

    /**
     * Recognizes a complete backtick span only within the current paragraph, preserving unmatched literal ticks.
     *
     * @param  string $description Complete authored Markdown used only for look-ahead.
     * @param  int    $offset      Byte offset immediately after the opening run.
     * @param  int    $length      Required closing-run length.
     * @return bool   Whether a matching run exists before the next blank paragraph boundary.
     */
    private static function closesSpan(string $description, int $offset, int $length): bool
    {
        $paragraph = preg_split('/\n[ \t]*\n/', substr($description, $offset), 2)[0];

        return 1 === preg_match('/(?<!`)`{' . $length . '}(?!`)/', $paragraph);
    }

    /**
     * Expands tabs at four-column stops for structural recognition; source strings remain owned by the caller.
     *
     * @param  string $line Source line whose visual columns determine list padding and fence indentation.
     * @return string Recognition-only representation with equivalent visual columns.
     */
    private static function expandTabs(string $line): string
    {
        $expanded = '';

        foreach (str_split($line) as $character) {
            $expanded .= "\t" === $character ? str_repeat(' ', 4 - (strlen($expanded) % 4)) : $character;
        }

        return $expanded;
    }
}
