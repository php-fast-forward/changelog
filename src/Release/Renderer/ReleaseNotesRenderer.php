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
use FastForward\Changelog\Markdown\BlockStructure;
use FastForward\Changelog\Markdown\SourceDocument;
use FastForward\Changelog\Template\TemplateInterface;
use InvalidArgumentException;

/** Builds readable localized release notes while preserving descriptions and useful links. */
final readonly class ReleaseNotesRenderer implements ReleaseNotesRendererInterface
{
    /**
     * Renders descriptions and references without leaking technical fragment metadata.
     * Empty paragraph separators stay empty; authored trailing hard breaks remain intact.
     * Prose starts beside its list marker; an opening fence keeps its existing block wrapper for history parsing.
     * An authored Markdown list keeps its markers and relative nesting, aligned with the other release entries.
     * Multiline entries end at a minimal blank boundary before the next independent fragment, including root HTML.
     *
     * @param list<Changeset>   $changesets independent author records
     * @param TemplateInterface $template   Validated structural presentation used to recognize or render history headings.
     * @param string|null       $repository Optional GitHub owner/name used for source selection and reference links.
     *
     * @return string                   Deterministically category-grouped release body preserving authored descriptions and optional references.
     * @throws InvalidArgumentException When an unclosed fence or HTML comment would hide delivery references.
     */
    public function render(array $changesets, TemplateInterface $template, ?string $repository = null): string
    {
        usort($changesets, static fn(Changeset $left, Changeset $right): int => strcmp($left->id, $right->id));
        $sections = [];
        foreach (Category::cases() as $category) {
            $entries = [];
            $previousOrdered = false;
            $previousMultiline = false;
            foreach ($changesets as $change) {
                if ($change->category !== $category) {
                    continue;
                }
                $references = $this->references($change, $repository);

                if ('' !== $references && BlockStructure::hasUnclosedFence($change->description)) {
                    throw new InvalidArgumentException(
                        'A fragment code fence needs an explicit closing delimiter before delivery references can be added.',
                    );
                }
                $body = BlockStructure::wrapDescription($change->description);
                $list = BlockStructure::listItem(explode("\n", $change->description, 2)[0]);
                $multiline = str_contains($change->description, "\n");
                $boundary = [] !== $entries && ($previousOrdered || $previousMultiline || ($list['ordered'] ?? false))
                    && ! str_ends_with(array_last($entries), "\n") ? "\n" : '';
                $previousMultiline = $multiline;

                if (null !== $list) {
                    $entry = $this->authoredList($body, $references);
                    $entries[] = $boundary . $entry;
                    $previousOrdered = $list['ordered'];

                    continue;
                }
                $suffix = '' === $references ? '' : ($multiline ? "\n\n  " : ' ') . '(' . $references . ')';
                $entries[] = $boundary . $body . $suffix;
                $previousOrdered = false;
            }
            if ([] !== $entries) {
                $sections[] = $template->categoryHeading($category->value) . "\n\n" . implode("\n", $entries);
            }
        }

        return [] === $sections ? '' : implode("\n\n", $sections) . "\n";
    }

    /**
     * Keeps principal items whose markers stay below the current item's content column, retaining nested content.
     *
     * @param  string                   $description Authored description beginning with a list marker.
     * @param  string                   $references  Optional already rendered references shared by this fragment's principal items.
     * @return string                   Authored list with references attached only after the owning item's content.
     * @throws InvalidArgumentException When an open HTML comment would hide delivery references.
     */
    private function authoredList(string $description, string $references): string
    {
        if ('' === $references) {
            return $description;
        }
        $document = SourceDocument::analyze($description);
        $items = $document->principalItems();
        $lines = explode("\n", $description);
        $output = [];

        foreach ($items as $index => $item) {
            $end = isset($items[$index + 1]) ? $items[$index + 1]['start'] - 1 : count($lines);
            $output[] = $this->listReference(
                array_slice($lines, $item['start'] - 1, $end - $item['start'] + 1),
                $item['padding'],
                $references,
            );
        }

        return implode("\n", $output);
    }

    /**
     * Adds inline references to single-line prose, or separate paragraphs after indented code and multiline items.
     *
     * @param  list<string>             $lines      Item lines after outer-indent alignment, retaining relative nesting and authored trailing whitespace.
     * @param  int                      $padding    Content columns used to keep the reference paragraph within its list item.
     * @param  string                   $references Already rendered delivery metadata for this item's fragment.
     * @return string                   Aligned item content with visible references added outside its inline constructs.
     * @throws InvalidArgumentException When an unclosed HTML comment would swallow the reference.
     */
    private function listReference(array $lines, int $padding, string $references): string
    {
        $body = implode("\n", $lines);

        if (BlockStructure::hasUnclosedComment($body)) {
            throw new InvalidArgumentException('An unclosed HTML comment would hide the list item delivery reference.');
        }

        if (1 === count($lines) && ! BlockStructure::startsIndentedItem($body)) {
            $text = rtrim($body, " \t\r");

            return $text . ' (' . $references . ')' . substr($body, strlen($text));
        }
        $trailing = [];

        while ([] !== $lines && '' === trim(array_last($lines))) {
            array_unshift($trailing, array_pop($lines));
        }

        return implode("\n", $lines) . "\n\n" . str_repeat(' ', $padding) . '(' . $references . ')'
            . ([] === $trailing ? '' : "\n" . implode("\n", $trailing));
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
