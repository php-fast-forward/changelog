<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://github.com/php-fast-forward/changelog/issues
 * @see       https://php-fast-forward.github.io/changelog/
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Checker;

use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Git\GitFileNotFoundException;
use FastForward\Changelog\Git\GitFileReaderInterface;
use FastForward\Changelog\Parser\ChangelogParserInterface;

/**
 * Compares Unreleased entries against an optional immutable Git baseline.
 */
final readonly class UnreleasedEntryChecker implements UnreleasedEntryCheckerInterface
{
    /**
     * Initializes comparison collaborators without performing I/O.
     *
     * @param PackageFilesystemInterface $filesystem reads the current changelog
     * @param GitFileReaderInterface $gitFileReader reads the baseline changelog
     * @param ChangelogParserInterface $parser parses both documents
     */
    public function __construct(
        private PackageFilesystemInterface $filesystem,
        private GitFileReaderInterface $gitFileReader,
        private ChangelogParserInterface $parser,
    ) {}

    /**
     * Detects entries that do not exist in the selected baseline.
     *
     * A missing current file is a legitimate absence and returns false. A file
     * missing from a valid baseline means every current entry is new. Other Git
     * failures MUST propagate so an unknown baseline cannot pass validation.
     */
    public function hasPendingChanges(
        string $file,
        ?string $againstReference = null,
        ?string $workingDirectory = null,
    ): bool {
        if (! $this->filesystem->exists($file)) {
            return false;
        }

        $currentEntries = $this->flattenEntries(
            $this->parser->parse($this->filesystem->readFile($file))->getUnreleased(),
        );

        if ([] === $currentEntries) {
            return false;
        }

        if (null === $againstReference) {
            return true;
        }

        try {
            $baseline = $this->gitFileReader->show(
                $againstReference,
                $file,
                $workingDirectory ?? $this->filesystem->getDirectory($file),
            );
        } catch (GitFileNotFoundException) {
            return true;
        }

        $baselineEntries = $this->flattenEntries(
            $this->parser->parse($baseline)->getUnreleased(),
        );

        return [] !== array_values(array_diff($currentEntries, $baselineEntries));
    }

    /**
     * Flattens entries with their category identity and first occurrence.
     *
     * @return list<string>
     */
    private function flattenEntries(ChangelogRelease $release): array
    {
        $entries = [];

        foreach ($release->getEntries() as $category => $categoryEntries) {
            foreach ($categoryEntries as $entry) {
                $entries[] = $category . "\0" . $entry;
            }
        }

        return array_values(array_unique($entries));
    }
}
