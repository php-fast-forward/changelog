<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Parser;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;

use function Safe\preg_match;
use function Safe\preg_match_all;
use function Safe\preg_split;
use function array_values;
use function preg_quote;
use function trim;

final class ChangelogParser
{
    public function parse(string $contents): ChangelogDocument
    {
        if ('' === trim($contents)) {
            return ChangelogDocument::create();
        }

        preg_match_all(
            '/^## \[(?<version>[^\]]+)\](?: - (?<date>\d{4}-\d{2}-\d{2}))?$/m',
            $contents,
            $matches,
            \PREG_OFFSET_CAPTURE,
        );

        if ([] === $matches[0]) {
            return ChangelogDocument::create();
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

            foreach (ChangelogEntryType::ordered() as $type) {
                $entries[$type->value] = $this->extractEntries($body, $type);
            }

            $releases[] = new ChangelogRelease(
                $matches['version'][$index][0],
                '' === ($matches['date'][$index][0] ?? '') ? null : $matches['date'][$index][0],
                $entries,
            );
        }

        return new ChangelogDocument($releases);
    }

    /**
     * @return list<string>
     */
    private function extractEntries(string $body, ChangelogEntryType $type): array
    {
        $pattern = \sprintf('/^### %s\s*(?:\R(?<body>.*?))?(?=^### |\z)/ms', preg_quote($type->value, '/'));

        if (1 !== preg_match($pattern, $body, $matches)) {
            return [];
        }

        $lines = preg_split('/\R/', trim($matches['body'] ?? ''));
        $entries = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if (! str_starts_with($line, '- ')) {
                continue;
            }

            $entry = trim(substr($line, 2));

            if ('' === $entry) {
                continue;
            }

            $entries[] = $entry;
        }

        return array_values(array_unique($entries));
    }
}
