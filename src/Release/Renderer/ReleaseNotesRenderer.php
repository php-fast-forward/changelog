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

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Template\TemplateInterface;

/** Builds readable localized release notes while preserving descriptions and useful links. */
final readonly class ReleaseNotesRenderer implements ReleaseNotesRendererInterface
{
    /**
     * Renders descriptions and references without leaking technical fragment metadata.
     * Empty paragraph separators stay empty; authored trailing hard breaks remain intact.
     * Prose starts beside its list marker; an opening fence keeps its existing block wrapper for history parsing.
     * A description beginning with a Markdown list retains its own markers and nesting instead of gaining a wrapper.
     *
     * @param list<Changeset>   $changesets independent author records
     * @param TemplateInterface $template   Validated structural presentation used to recognize or render history headings.
     * @param string|null       $repository Optional GitHub owner/name used for source selection and reference links.
     *
     * @return string Deterministically category-grouped release body preserving authored descriptions and optional references.
     */
    public function render(array $changesets, TemplateInterface $template, ?string $repository = null): string
    {
        usort($changesets, static fn(Changeset $left, Changeset $right): int => strcmp($left->id, $right->id));
        $sections = [];
        foreach (Category::cases() as $category) {
            $entries = [];
            foreach ($changesets as $change) {
                if ($change->category !== $category) {
                    continue;
                }
                $references = $this->references($change, $repository);
                $list = $this->listItem(explode("\n", $change->description, 2)[0]);

                if (null !== $list) {
                    $entries[] = $this->authoredList($change->description, $references, $list['indent']);

                    continue;
                }
                $multiline = str_contains($change->description, "\n");
                $fenced = $multiline
                    && 1 === preg_match('/\A {0,3}(`{3,}|~{3,})([^\n]*)/', $change->description, $fence)
                    && ('~' === $fence[1][0] || ! str_contains($fence[2], '`'));
                $body = ($fenced ? "-\n  " : '- ') . preg_replace('/\n(?!\n|\z)/', "\n  ", $change->description);
                $suffix = '' === $references ? '' : ($multiline ? "\n\n  " : ' ') . '(' . $references . ')';
                $entries[] = $body . $suffix;
            }
            if ([] !== $entries) {
                $sections[] = $template->categoryHeading($category->value) . "\n\n" . implode("\n", $entries);
            }
        }

        return [] === $sections ? '' : implode("\n\n", $sections) . "\n";
    }

    /**
     * Keeps authored list structure, attaching references only to its principal items outside fenced code.
     *
     * Prose references precede original trailing whitespace so Markdown hard breaks survive. An item opening
     * directly with a fence receives an indented reference paragraph after its block, never in the info string.
     * This bounded block scan is not a general Markdown parser; it never rewrites authored marker/content bytes.
     *
     * @param  string $description Validated authored description beginning with a recognized list marker.
     * @param  string $references  Optional already rendered references, shared by this fragment's principal items.
     * @param  int    $indent      Original principal-item indentation; deeper markers remain nested content.
     * @return string Original list bytes with optional references, without an additional list wrapper.
     */
    private function authoredList(string $description, string $references, int $indent): string
    {
        if ('' === $references) {
            return $description;
        }
        $lines = explode("\n", $description);
        $output = [];
        $fence = null;
        $pendingIndent = null;

        foreach ($lines as $line) {
            if (null !== $fence) {
                $output[] = $line;

                if (1 === preg_match(
                    '/\A {0,' . (3 + $fence[2]) . '}' . preg_quote($fence[0], '/') . '{' . $fence[1] . ',}[ \t\r]*\z/',
                    $line,
                )) {
                    $fence = null;

                    if (null !== $pendingIndent) {
                        $output[] = "\n" . str_repeat(' ', $pendingIndent) . '(' . $references . ')';
                        $pendingIndent = null;
                    }
                }

                continue;
            }
            $item = $this->listItem($line);
            $principal = null !== $item && $item['indent'] === $indent;

            $opening = 1 === preg_match(
                '/\A {0,3}((?:(?:[-+*]|[0-9]{1,9}[.)])[ \t]+)?)(`{3,}|~{3,})([^\r\n]*)/',
                $line,
                $matches,
            )
                && ('~' === $matches[2][0] || ! str_contains($matches[3], '`'));

            if ($opening) {
                $fence = [$matches[2][0], strlen($matches[2]), strlen($matches[1])];
            }

            if ($principal) {
                if ($opening) {
                    $pendingIndent = $item['contentIndent'];
                } else {
                    $text = rtrim($line, " \t\r");
                    $line = $text . ' (' . $references . ')' . substr($line, strlen($text));
                }
            }
            $output[] = $line;
        }

        return implode("\n", $output);
    }

    /**
     * Recognizes a Markdown list marker with at most three leading spaces, excluding thematic breaks.
     *
     * @param  string                                   $line Authored single line without its LF separator; original spaces and optional CR are retained.
     * @return array{indent:int,contentIndent:int}|null Marker context, or null for ordinary Markdown.
     */
    private function listItem(string $line): ?array
    {
        if (1 === preg_match('/\A {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})\r?\z/', $line)
            || 1 !== preg_match('/\A( {0,3})([-+*]|[0-9]{1,9}[.)])([ \t]+).*\z/', $line, $matches)) {
            return null;
        }

        return [
            'indent' => strlen($matches[1]),
            'contentIndent' => strlen($matches[1] . $matches[2] . $matches[3]),
        ];
    }

    /**
     * Links trusted numeric references when a repository is known, retaining plain references otherwise.
     *
     * @param Changeset   $change     Validated fragment whose optional issue, pull-request and author references are rendered.
     * @param string|null $repository Optional GitHub owner/name used for source selection and reference links.
     *
     * @return string Rendered issue, pull-request and author references, using repository links when available.
     */
    private function references(Changeset $change, ?string $repository): string
    {
        $references = [];
        foreach (['pull' => $change->pullRequest, 'issues' => $change->issue] as $kind => $number) {
            if (null !== $number) {
                $references[] = null === $repository ? '#' . $number
                    : '[#' . $number . '](https://github.com/' . $repository . '/' . $kind . '/' . $number . ')';
            }
        }
        if (null !== $change->author) {
            $references[] = str_ends_with($change->author, '[bot]') ? '@' . $change->author
                : '[@' . $change->author . '](https://github.com/' . rawurlencode($change->author) . ')';
        }

        return implode(', ', $references);
    }
}
