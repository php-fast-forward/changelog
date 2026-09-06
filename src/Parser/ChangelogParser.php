<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Parser;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogDocumentFactoryInterface;
use FastForward\Changelog\Document\ChangelogReleaseFactoryInterface;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Entry\ChangelogEntryTypesInterface;

use function Safe\preg_match;
use function Safe\preg_match_all;
use function Safe\preg_split;
use function array_values;
use function preg_quote;
use function trim;

/**
 * Parses the supported Keep a Changelog Markdown subset into domain values.
 *
 * Unsupported prose MAY be ignored. Release headings and bullet entries MUST be
 * parsed deterministically without performing filesystem access.
 */
final readonly class ChangelogParser implements ChangelogParserInterface
{
    /**
     * Composes factories and canonical entry types used during parsing.
     *
     * @param ChangelogDocumentFactoryInterface $documentFactory creates normalized documents
     * @param ChangelogReleaseFactoryInterface $releaseFactory creates parsed releases
     * @param ChangelogEntryTypesInterface $entryTypes supplies canonical categories
     */
    public function __construct(
        private ChangelogDocumentFactoryInterface $documentFactory,
        private ChangelogReleaseFactoryInterface $releaseFactory,
        private ChangelogEntryTypesInterface $entryTypes,
    ) {}

    /**
     * Parses Markdown contents into a normalized changelog document.
     *
     * Empty contents or contents without supported release headings MUST return
     * a document containing an empty Unreleased section.
     */
    public function parse(string $contents): ChangelogDocument
    {
        if ('' === trim($contents)) {
            return $this->documentFactory->create();
        }

        preg_match_all(
            '/^## \[(?<version>[^\]]+)\](?: - (?<date>\d{4}-\d{2}-\d{2}))?\r?$/m',
            $contents,
            $matches,
            \PREG_OFFSET_CAPTURE,
        );

        if ([] === $matches[0]) {
            return $this->documentFactory->create();
        }

        $releases = [];
        $sectionCount = \count($matches[0]);

        for ($index = 0; $index < $sectionCount; ++$index) {
            $heading = $matches[0][$index][0];
            $offset = $matches[0][$index][1];
            $bodyStart = $offset + \strlen((string) $heading);
            $bodyEnd = $matches[0][$index + 1][1] ?? \strlen($contents);
            $body = trim(substr($contents, $bodyStart, $bodyEnd - $bodyStart));

            $entries = [];

            foreach ($this->entryTypes->ordered() as $type) {
                $entries[$type->value] = $this->extractEntries($body, $type);
            }

            $releases[] = $this->releaseFactory->create(
                $matches['version'][$index][0],
                '' === ($matches['date'][$index][0] ?? '') ? null : $matches['date'][$index][0],
                $entries,
            );
        }

        return $this->documentFactory->create($releases, $this->extractReferences($contents));
    }

    /**
     * Extracts unique bullet entries for one category section.
     *
     * Indented continuation lines and nested bullets MUST remain attached to
     * their top-level entry so a parse-render cycle does not lose Markdown.
     *
     * @return list<string>
     */
    private function extractEntries(string $body, ChangelogEntryType $type): array
    {
        $pattern = \sprintf('/^### %s\s*(?:\R(?<body>.*?))?(?=^### |\z)/ms', preg_quote($type->value, '/'));

        if (1 !== preg_match($pattern, $body, $matches)) {
            return [];
        }

        $lines = preg_split('/\R/', \rtrim($matches['body'] ?? ''));
        $entries = [];
        $entry = null;

        foreach ($lines as $line) {
            $line = (string) $line;

            if (str_starts_with($line, '- ')) {
                if (null !== $entry) {
                    $entries[] = \rtrim($entry);
                }

                $entry = trim(substr($line, 2));

                continue;
            }

            if (null !== $entry && ('' === $line || 1 === preg_match('/^[ \t]+/', $line))) {
                $entry .= "\n" . $line;
            }
        }

        if (null !== $entry) {
            $entries[] = \rtrim($entry);
        }

        return array_values(array_unique($entries));
    }

    /**
     * Extracts Markdown reference definitions so mutations can preserve them.
     *
     * @return list<string>
     */
    private function extractReferences(string $contents): array
    {
        preg_match_all('/^\[[^\]\r\n]+\]:[ \t]+\S.*\r?$/m', $contents, $matches);
        $references = [];

        foreach ($matches[0] as $reference) {
            $references[] = \rtrim((string) $reference, "\r");
        }

        return $references;
    }
}
