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

/** Projects source-backed Markdown classification into the library's release-entry presentation. */
final class BlockStructure
{
    /**
     * Exposes a first-line principal marker without inferring container grammar independently.
     *
     * @param  string                                                $line Original source line; tabs and literal payload remain untouched.
     * @return array{indent:int,contentIndent:int,ordered:bool}|null Upstream-classified marker columns, or null for prose.
     */
    public static function listItem(string $line): ?array
    {
        $item = SourceDocument::analyze($line)->principalItems()[0] ?? null;

        return null === $item || 1 !== $item['start'] ? null
            : ['indent' => $item['indent'], 'contentIndent' => $item['padding'], 'ordered' => $item['ordered']];
    }

    /**
     * Whether a delivery suffix would become literal indented code.
     *
     * @param  string $line First principal item line.
     * @return bool   Whether a delivery suffix would become literal indented code.
     */
    public static function startsIndentedItem(string $line): bool
    {
        return SourceDocument::analyze($line)->beginsWithIndentedCode();
    }

    /**
     * Aligns each principal item independently, or wraps standalone prose/code in one release item.
     *
     * Source ranges come from CommonMark. Only structural leading indentation is adjusted; fenced and HTML payloads,
     * literal tabs beyond indented-code boundaries and authored trailing hard breaks retain their bytes.
     * Stored fragments and historical documents are never rewritten by this presentation operation.
     *
     * @param  string $description Original fragment description.
     * @return string Effective release-entry presentation shared by validation and rendering.
     */
    public static function wrapDescription(string $description): string
    {
        $document = SourceDocument::analyze($description);
        $lines = $document->lines();

        foreach ($lines as $number => &$line) {
            if (! isset($document->fencedLines()[$number]) && ! isset($document->literalHtmlLines()[$number])) {
                $line = self::structuralIndentation($line, $document->indentationLimits()[$number] ?? 4);
            }
        }
        unset($line);
        $items = $document->principalItems();

        if ([] !== $items && 1 === $items[0]['start']) {
            foreach ($items as $item) {
                $indent = min($items[0]['indent'], $item['indent']);

                foreach (range($item['start'], $item['end']) as $number) {
                    if ($indent > 0) {
                        $lines[$number] = preg_replace('/\A {0,' . $indent . '}/', '', $lines[$number]);
                    }
                }
            }

            return self::nestTrailingReferences(implode('', $lines));
        }
        $description = implode('', $lines);
        $fenced = $document->beginsWithFencedCode();

        return ($fenced ? "-\n  " : '- ') . preg_replace('/\n(?!\n|\z)/', "\n  ", $description);
    }

    /**
     * Whether effective presentation contains a fence without an explicit delimiter.
     *
     * @param  string $description Authored Markdown.
     * @return bool   Whether effective presentation contains a fence without an explicit delimiter.
     */
    public static function hasUnclosedFence(string $description): bool
    {
        return SourceDocument::analyze(self::wrapDescription($description))->hasUnclosedFence();
    }

    /**
     * Whether effective presentation could hide appended delivery references in an unfinished comment.
     *
     * @param  string $description Authored Markdown.
     * @return bool   Whether effective presentation could hide appended delivery references in an unfinished comment.
     */
    public static function hasUnclosedComment(string $description): bool
    {
        return SourceDocument::analyze(self::wrapDescription($description))->hasUnclosedComment();
    }

    /**
     * Requires an effective fragment tail to leave the next root release heading visible.
     *
     * @param  string $description Authored fragment whose actual list presentation determines HTML containment.
     * @return bool   Whether raw HTML would swallow a subsequent root heading after a blank boundary.
     */
    public static function hasUncontainedHtmlTail(string $description): bool
    {
        return SourceDocument::analyze(self::wrapDescription($description))->hidesAppendedLine(
            '## Fast Forward Boundary',
        );
    }

    /**
     * Expands only indentation consumed by structural columns, retaining subsequent literal payload tabs.
     *
     * @param  string $line  Original source line outside a fenced block.
     * @param  int    $limit Content boundary plus four indented-code columns, determined by the classified containers.
     * @return string Equivalent structural spacing followed by unchanged remaining source bytes.
     */
    private static function structuralIndentation(string $line, int $limit): string
    {
        $offset = 0;
        $column = 0;
        $prefix = '';

        while ($column < $limit && isset($line[$offset]) && (' ' === $line[$offset] || "\t" === $line[$offset])) {
            $width = "\t" === $line[$offset] ? 4 - ($column % 4) : 1;
            $prefix .= str_repeat(' ', $width);
            $column += $width;
            ++$offset;
        }

        return $prefix . substr($line, $offset);
    }

    /**
     * Keeps terminal definitions active within their owning list item or the intervening root body.
     *
     * @param  string $description Independently aligned authored list.
     * @return string Presentation with terminal definitions distinguished from the history footer without becoming code.
     */
    private static function nestTrailingReferences(string $description): string
    {
        $document = SourceDocument::analyze($description);
        $lines = $document->lines();
        $offsets = $document->referencePositions();
        $items = $document->principalItems();
        $item = array_last($items);
        $definitions = [];

        foreach (array_reverse(array_keys($lines)) as $number) {
            if (isset($offsets[$document->offset($number)]) && str_starts_with($lines[$number], '[')) {
                $definitions[] = $number;
            } elseif ('' !== trim($lines[$number])) {
                break;
            }
        }

        if ([] === $definitions) {
            return $description;
        }
        $firstDefinition = min($definitions);
        $padding = $item['padding'];

        foreach ($lines as $number => $line) {
            if ($number > $item['end'] && $number < $firstDefinition && '' !== trim($line)) {
                // Root blocks ended the item; at most three columns keep a body-owned definition active.
                $padding = min(3, $padding);

                break;
            }
        }

        foreach ($definitions as $number) {
            $lines[$number] = str_repeat(' ', $padding) . $lines[$number];
        }

        return implode('', $lines);
    }
}
