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

namespace FastForward\Changelog\Release;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Template\TemplateInterface;

/** Consumes the legacy pending section when fragments establish an actual release. */
final readonly class ReleaseHistoryConsolidator implements ReleaseHistoryConsolidatorInterface
{
    /**
     * Injects value construction; consolidation performs no I/O or clock access.
     *
     * @param HistoryReleaseFactoryInterface   $releases   Constructs strict semantic sections with validated dates and preserved raw presentation.
     * @param ReleaseExceptionFactoryInterface $exceptions Constructs controlled domain diagnostics without exposing underlying host details.
     */
    public function __construct(
        private HistoryReleaseFactoryInterface $releases,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /**
     * Preserves descriptions, fenced examples and prior releases while removing the pending heading.
     * The planner must reject an already maintained target version before calling this operation.
     *
     * @param HistoryDocument   $document Maintained history whose release bodies, introduction and references must be preserved.
     * @param string            $version  Concrete semantic version receiving combined legacy pending descriptions and new fragment notes.
     * @param string|null       $date     Observed calendar date in YYYY-MM-DD form, or null when unavailable.
     * @param string            $notes    Exact rendered body of the selected new release, without its outer heading.
     * @param TemplateInterface $template Validated structural presentation used to recognize or render history headings.
     *
     * @return HistoryDocument History containing the concrete release with legacy pending notes promoted exactly once.
     *
     * @throws \InvalidArgumentException When legacy category markers, the target semantic identity or the supplied calendar date are invalid.
     */
    public function promote(
        HistoryDocument $document,
        string $version,
        ?string $date,
        string $notes,
        TemplateInterface $template,
    ): HistoryDocument {
        $legacy = ['' => ''];
        $hasLegacy = false;
        $ending = '';
        foreach ($document->getReleases() as $pending) {
            if ('unreleased' !== $pending->getVersion()) {
                continue;
            }
            $ending .= preg_replace('/\A<!-- fast-forward-changelog:end-release -->\r?\n?/', '', $pending->getEnding());
            if ('' === trim($pending->getBody())) {
                continue;
            }
            $hasLegacy = true;
            foreach ($this->sections($pending->getBody(), $template) as $key => $body) {
                $legacy[$key] = $this->join($legacy[$key] ?? '', $body, null !== Category::tryFrom($key));
            }
        }
        if ($hasLegacy) {
            $current = $this->sections($notes, $template);
            $notes = $this->join($legacy[''], $current['']);
            foreach (Category::cases() as $category) {
                $body = $this->join($legacy[$category->value] ?? '', $current[$category->value] ?? '', true);
                if ('' !== trim($body)) {
                    $notes = $this->join(
                        $notes,
                        $template->categoryHeading($category->value) . "\n\n" . ltrim($body, "\r\n"),
                    );
                }
                $notes = $this->join(
                    $notes,
                    $this->join(
                        $legacy[$category->value . ':after'] ?? '',
                        $current[$category->value . ':after'] ?? '',
                    ),
                );
            }
        }
        $retained = array_values(
            array_filter(
                $document->getReleases(),
                static fn(HistoryRelease $release): bool => 'unreleased' !== $release->getVersion(),
            ),
        );
        array_unshift($retained, $this->releases->create($version, $date, 'release-plan', $notes, null, $ending));

        return $document->withReleases($retained);
    }

    /**
     * Separates recognized category structure without parsing or rewriting descriptions.
     * Only unindented level-three headings define categories, matching canonical
     * history output; headings inside list descriptions remain literal data.
     * Fenced examples and ordinary comments remain data. Reserved legacy markers
     * are removed; unknown peer blocks follow their original preceding category
     * after that category receives its new entries.
     *
     * @param string            $body     Raw release body bytes, preserving Markdown whitespace and code fences.
     * @param TemplateInterface $template Validated structural presentation used to recognize or render history headings.
     *
     * @return array<string,string> prose, category bodies and following unknown peer blocks
     */
    private function sections(string $body, TemplateInterface $template): array
    {
        $headings = [];
        foreach (Category::cases() as $category) {
            $headings['### ' . $category->heading()] = $category->value;
        }
        foreach (['Adicionado' => 'added', 'Modificado' => 'changed', 'Obsoleto' => 'deprecated', 'Removido' => 'removed', 'Corrigido' => 'fixed', 'Segurança' => 'security'] as $heading => $category) {
            $headings['### ' . $heading] ??= $category;
        }
        foreach (Category::cases() as $category) {
            $headings[rtrim($template->categoryHeading($category->value), " \t")] = $category->value;
        }
        $sections = ['' => ''];
        $active = '';
        $unknown = false;
        $fence = null;
        $markedCategory = null;
        foreach (preg_split('/(?<=\n)/', $body) as $line) {
            $heading = rtrim($line, " \t\r\n");
            $outside = $this->outsideFence($line, $fence);
            $peer = $outside && 1 === preg_match('/^###(?:[ \t]|\r?\n|\z)/', $line);
            if (null !== $markedCategory && '' !== trim($line) && ! $peer) {
                throw $this->exceptions->invalid('Legacy category markers must be followed by a level-three heading.');
            }
            if (null !== $markedCategory && '' === trim($line)) {
                continue;
            }
            if ($outside && 1 === preg_match(
                '/^<!-- fast-forward-changelog:category (added|changed|deprecated|removed|fixed|security) -->\r?\n?\z/',
                $line,
                $matches,
            )) {
                $markedCategory = $matches[1];

                continue;
            }
            if ($outside && 1 === preg_match('/^<!-- fast-forward-changelog:fragment \{.*\} -->\r?\n?\z/', $line)) {
                continue;
            }
            if ($outside && (null !== $markedCategory || isset($headings[$heading]))) {
                $active = $markedCategory ?? $headings[$heading];
                $markedCategory = null;
                $unknown = false;
                $sections[$active] ??= '';

                continue;
            }
            if ($peer) {
                $unknown = true;
            }
            $key = $unknown && '' !== $active ? $active . ':after' : $active;
            $sections[$key] ??= '';
            $sections[$key] .= $line;
        }
        if (null !== $markedCategory) {
            throw $this->exceptions->invalid('Legacy category markers must be followed by a level-three heading.');
        }

        return $sections;
    }

    /**
     * Joins body boundaries without stripping meaningful spaces; consecutive simple list entries stay compact.
     *
     * @param string $left        Existing body bytes whose meaningful trailing spaces must survive concatenation.
     * @param string $right       New body bytes appended with only the structural boundary spacing required.
     * @param bool   $compactList Whether adjacent simple list entries may share one newline instead of a blank separator.
     *
     * @return string Combined bodies with meaningful Markdown spaces retained and only the required paragraph or compact-list separator added.
     */
    private function join(string $left, string $right, bool $compactList = false): string
    {
        if ('' === trim($left)) {
            return $right;
        }
        if ('' === trim($right)) {
            return $left;
        }
        $newline = str_ends_with($left, "\r\n") ? "\r\n" : "\n";
        $left = rtrim($left, "\r\n");
        $right = ltrim($right, "\r\n");
        $separator = $compactList && 1 === preg_match('/(?:\A|\n)- [^\r\n]*\z/', $left)
            && str_starts_with($right, '- ') ? $newline : $newline . $newline;

        return $left . $separator . $right;
    }

    /**
     * Distinguishes structural headings from backtick and tilde fenced examples.
     *
     * @param string                                  $line  Current source Markdown line, including its original line ending.
     * @param array{character:string,length:int}|null $fence Mutable active fence state, updated by reference as source lines open or close code blocks.
     *
     * @return bool Whether the current line may define category structure; fence delimiters return false while updating the supplied state.
     */
    private function outsideFence(string $line, ?array &$fence): bool
    {
        if (null !== $fence) {
            if (1 === preg_match(
                '/^ {0,3}' . preg_quote($fence['character'], '/') . '{' . $fence['length'] . ',}[ \t]*(?:\r?\n|\z)/',
                $line,
            )) {
                $fence = null;
            }

            return false;
        }
        if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})(.*)/', $line, $matches)
            && ('~' === $matches[1][0] || ! str_contains($matches[2], '`'))) {
            $fence = ['character' => $matches[1][0], 'length' => strlen($matches[1])];

            return false;
        }

        return true;
    }
}
