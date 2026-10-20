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
 */

namespace FastForward\Changelog\History;

use FastForward\Changelog\Markdown\BlockStructure;

/** Completes concrete release links without rewriting maintained Markdown or observing external state. */
final readonly class HistoryReferenceBuilder
{
    /**
     * Inserts missing reference definitions in section order while retaining existing definition bytes.
     *
     * Semantic precedence selects predecessors independently of section order. Distinct build
     * identities with equal precedence use lexical identity order. Existing definitions remain
     * authoritative, including definitions outside the terminal footer; fenced examples do not.
     * Supplied tag evidence limits additions and predecessors to reachable stable releases,
     * plus the explicitly planned release. Null retains history-only completion for existing
     * callers; an empty list never treats a maintained section as publication evidence.
     *
     * @param HistoryDocument   $document       Maintained sections and raw presentation to retain unchanged.
     * @param string|null       $repository     Known GitHub owner/name, or null when no destination can be established.
     * @param string            $tagPrefix      Validated prefix prepended to each complete semantic version in Git tag URLs.
     * @param list<string>|null $reachableTags  Exact tag names already proven ancestral to the source base, or null for history-only completion.
     * @param string|null       $plannedVersion Canonical prospective release present in the document; its tag is not asserted to exist yet.
     *
     * @return HistoryDocument Independent document with missing links, or the original when no additions are possible.
     */
    public function complete(
        HistoryDocument $document,
        ?string $repository,
        string $tagPrefix = 'v',
        ?array $reachableTags = null,
        ?string $plannedVersion = null,
    ): HistoryDocument {
        if (null === $repository || 1 !== preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~', $repository)) {
            return $document;
        }

        $versions = $this->versions($document);
        $ordered = $this->linkedVersions($versions, $tagPrefix, $reachableTags, $plannedVersion);
        usort($ordered, $this->compare(...));
        $defined = $this->definedLabels($document);
        $destinations = [];
        $previous = null;
        [$owner, $name] = explode('/', $repository);
        $root = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($name);

        foreach ($ordered as $version) {
            $tag = rawurlencode($tagPrefix . $version);
            $destinations[$version] = null === $previous ? $root . '/releases/tag/' . $tag
                : $root . '/compare/' . rawurlencode($tagPrefix . $previous) . '...' . $tag;
            $previous = $version;
        }

        $additions = [];
        foreach ($versions as $version => $label) {
            $normalized = strtolower($label);
            if (!isset($destinations[$version]) || isset($defined[$normalized])) {
                continue;
            }

            $additions[$version] = '[' . $label . ']: ' . $destinations[$version];
            $defined[$normalized] = 0;
        }

        if ([] === $additions) {
            return $document;
        }

        return $document->withReferences($this->insertReferences($document, $versions, $additions));
    }

    /**
     * Selects reference targets without inferring tags from untagged historical sections.
     *
     * Reachable tags may supply predecessors even when no matching section is maintained.
     * Existing definitions are preserved separately, including definitions for untagged sections.
     *
     * @param array<string,string> $versions       Validated document identities and their original heading labels.
     * @param string               $tagPrefix      Exact configured prefix removed from reachable tag names.
     * @param list<string>|null    $reachableTags  Caller-proven ancestral tag names, or null for the original history-only contract.
     * @param string|null          $plannedVersion Explicit prospective identity; ignored when absent from the validated document.
     *
     * @return list<string> Unique stable tag identities and the requested prospective release.
     */
    private function linkedVersions(
        array $versions,
        string $tagPrefix,
        ?array $reachableTags,
        ?string $plannedVersion,
    ): array {
        if (null === $reachableTags) {
            return array_keys($versions);
        }

        $linked = [];
        foreach ($reachableTags as $tag) {
            if (!str_starts_with($tag, $tagPrefix)) {
                continue;
            }
            $version = substr($tag, strlen($tagPrefix));
            if (1 === preg_match(
                '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/',
                $version,
            )) {
                $linked[$version] = true;
            }
        }
        if (null !== $plannedVersion && isset($versions[$plannedVersion])) {
            $linked[$plannedVersion] = true;
        }

        return array_keys($linked);
    }

    /**
     * Places each missing definition before the next retained version in historical section order.
     *
     * Existing definitions, unrelated links and blank lines retain their bytes and relative order.
     * Versions without a following footer anchor are appended, using only the required boundary.
     * Offsets refer to the original footer; applying groups backwards keeps every anchor stable.
     *
     * @param HistoryDocument      $document  Maintained history supplying the original footer and final prose boundary.
     * @param array<string,string> $versions  Concrete identities and labels in historical section order.
     * @param array<string,string> $additions Missing identities mapped to their complete single-line definitions.
     *
     * @return string Footer with deterministic insertions and unchanged existing definition destinations.
     */
    private function insertReferences(HistoryDocument $document, array $versions, array $additions): string
    {
        $references = $document->getReferences();
        $newline = str_contains($references, "\r\n") ? "\r\n" : "\n";
        $offsets = $this->referenceOffsets($references);
        $anchor = strlen($references);
        $insertions = [];

        foreach (array_reverse($versions, true) as $version => $label) {
            $normalized = strtolower($label);
            if (isset($offsets[$normalized])) {
                $anchor = $offsets[$normalized];
            } elseif (isset($additions[$version])) {
                $insertions[$anchor] ??= [];
                array_unshift($insertions[$anchor], $additions[$version]);
            }
        }

        krsort($insertions, SORT_NUMERIC);
        foreach ($insertions as $offset => $lines) {
            $separator = strlen($references) === $offset ? $this->separator($document, $newline) : '';
            $references = substr($references, 0, $offset) . $separator . implode($newline, $lines) . $newline
                . substr($references, $offset);
        }

        return $references;
    }

    /**
     * Selects strict semantic identities and the bracketed labels actually used by retained headings.
     *
     * @param HistoryDocument $document Historical sections in their original presentation order.
     *
     * @return array<string,string> Concrete versions mapped to their retained or canonical reference labels.
     */
    private function versions(HistoryDocument $document): array
    {
        $identifier = '(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)';
        $pattern = '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
            . '(?:-' . $identifier . '(?:\.' . $identifier . ')*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/';
        $versions = [];

        foreach ($document->getReleases() as $release) {
            $version = $release->getVersion();
            if (1 !== preg_match($pattern, $version)) {
                continue;
            }

            $label = $version;
            if (1 === preg_match(
                '/\[([vV]?' . preg_quote($version, '/') . ')\]/',
                $release->getHeading() ?? '',
                $matches,
            )) {
                $label = $matches[1];
            }
            $versions[$version] = $label;
        }

        return $versions;
    }

    /**
     * Compares arbitrary-width SemVer cores and prereleases without converting numeric identifiers to integers.
     *
     * @param string $left  First strict semantic version, including optional prerelease and build identifiers.
     * @param string $right Second strict semantic version; lexical identity resolves equal semantic precedence.
     *
     * @return int Negative, zero or positive ordering by semantic precedence, then complete identity for build ties.
     */
    private function compare(string $left, string $right): int
    {
        $leftParts = explode('-', explode('+', $left, 2)[0], 2);
        $rightParts = explode('-', explode('+', $right, 2)[0], 2);
        $rightCore = explode('.', $rightParts[0]);
        foreach (explode('.', $leftParts[0]) as $index => $component) {
            $comparison = $this->numeric($component, $rightCore[$index]);
            if (0 !== $comparison) {
                return $comparison;
            }
        }

        if (!isset($leftParts[1]) || !isset($rightParts[1])) {
            $comparison = isset($rightParts[1]) <=> isset($leftParts[1]);

            return 0 === $comparison ? strcmp($left, $right) : $comparison;
        }

        $leftPre = explode('.', $leftParts[1]);
        $rightPre = explode('.', $rightParts[1]);
        foreach ($leftPre as $index => $component) {
            if (!isset($rightPre[$index])) {
                return 1;
            }
            $other = $rightPre[$index];
            $leftNumeric = ctype_digit($component);
            $rightNumeric = ctype_digit($other);
            $comparison = $leftNumeric && $rightNumeric ? $this->numeric($component, $other)
                : ($leftNumeric === $rightNumeric ? strcmp($component, $other) : $rightNumeric <=> $leftNumeric);
            if (0 !== $comparison) {
                return $comparison;
            }
        }

        $comparison = count($leftPre) <=> count($rightPre);

        return 0 === $comparison ? strcmp($left, $right) : $comparison;
    }

    /**
     * Orders canonical unsigned decimal components without overflow or floating-point coercion.
     *
     * @param string $left  First numeric identifier with no redundant leading zeros.
     * @param string $right Second numeric identifier with no redundant leading zeros.
     *
     * @return int Numeric order derived from decimal width and lexical digits.
     */
    private function numeric(string $left, string $right): int
    {
        $comparison = strlen($left) <=> strlen($right);

        return 0 === $comparison ? strcmp($left, $right) : $comparison;
    }

    /**
     * Finds case-insensitive reference labels outside fenced code, indented code and HTML comment blocks.
     *
     * @param HistoryDocument $document Raw maintained Markdown whose definitions must never be overwritten.
     *
     * @return array<string,int> Existing ASCII-normalized labels and offsets, including definitions followed by prose.
     */
    private function definedLabels(HistoryDocument $document): array
    {
        $markdown = $document->getPrefix();
        foreach ($document->getReleases() as $release) {
            // Rendered sections have structural line boundaries; this scan never closes fences or rewrites the source.
            $markdown .= "\n" . ($release->getHeading() ?? '') . $release->getBody() . $release->getEnding();
        }
        $markdown .= $document->getReferences();

        return $this->referenceOffsets($markdown);
    }

    /**
     * Locates active reference definitions without interpreting examples or rewriting source Markdown.
     *
     * List/quote-contained fences use the same recognition as rendering and historical parsing. HTML comments
     * retain precedence over delimiter-looking content until they close; original byte offsets remain authoritative.
     *
     * @param string $markdown Raw document or footer whose fenced, indented and commented examples are ignored.
     *
     * @return array<string,int> First byte offset for each ASCII-normalized active reference label.
     */
    private function referenceOffsets(string $markdown): array
    {
        $defined = [];
        $fence = null;
        $containers = [];
        $comment = false;
        foreach (preg_split('/\r\n|\n|\r/', $markdown, flags: PREG_SPLIT_OFFSET_CAPTURE) as [$line, $offset]) {
            if (! BlockStructure::structuralLine($line, $fence, $containers, $comment)) {
                continue;
            }
            if (1 === preg_match('/^ {0,3}\[([^\]\r\n]+)\]:/', $line, $matches)) {
                $defined[strtolower(trim($matches[1]))] ??= $offset;
            }
        }

        return $defined;
    }

    /**
     * Adds only the boundary required to keep appended definitions outside the final prose paragraph.
     *
     * @param HistoryDocument $document Existing footer and final section used to determine the untouched trailing bytes.
     * @param string          $newline  New reference-line ending, matching an existing CRLF footer when present.
     *
     * @return string Empty bytes or the minimal newline boundary needed before the new definitions.
     */
    private function separator(HistoryDocument $document, string $newline): string
    {
        $references = $document->getReferences();
        if ('' !== $references) {
            return str_ends_with($references, "\n") || str_ends_with($references, "\r") ? '' : $newline;
        }

        $last = array_last($document->getReleases());
        $tail = ($last->getHeading() ?? '') . $last->getBody() . $last->getEnding();
        if (null === $last->getHeading() && !str_ends_with($tail, "\n")) {
            $tail .= "\n";
        }
        if (1 === preg_match('/(?:\r?\n){2}\z|\r\r\z/', $tail)) {
            return '';
        }

        return str_ends_with($tail, "\n") || str_ends_with($tail, "\r") ? $newline : $newline . $newline;
    }
}
