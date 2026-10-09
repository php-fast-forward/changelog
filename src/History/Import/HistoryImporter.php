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

namespace FastForward\Changelog\History\Import;

use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryExceptionFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\Import\Factory\HistoryImportResultFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\TemplateInterface;

/** Backfills only absent stable Git versions without rewriting historical notes or provenance. */
final readonly class HistoryImporter implements HistoryImporterInterface
{
    /** Injects all network and value-construction boundaries for deterministic unit tests. */
    public function __construct(
        private GitHubClientInterface $github,
        private HistoryDocumentFactoryInterface $documents,
        private HistoryReleaseFactoryInterface $releases,
        private HistoryImportResultFactoryInterface $results,
        private HistoryExceptionFactoryInterface $exceptions,
    ) {}

    /**
     * Imports published GitHub notes when configured, otherwise explicit missing-note messages.
     * Only tags matching the exact prefix and strict stable SemVer are candidates. GitHub
     * cannot invent a version absent from Git. Existing release objects MUST stay intact.
     */
    public function import(
        HistoryDocument $document,
        ReleaseOptions $options,
        TemplateInterface $template,
        array $tags,
    ): HistoryImportResult {
        if ('github' === $options->source && null === $options->repository) {
            throw $this->exceptions->invalid('GitHub history source requires an explicit repository.');
        }
        $versions = $this->versions($tags, $options->tagPrefix);
        $current = array_key_first($versions) ?? '0.0.0';
        $missing = array_filter(
            array_keys($versions),
            static fn(string $version): bool => null === $document->getRelease($version),
        );
        if ([] === $missing) {
            return $this->results->create($document, [], $current);
        }
        $published = [];
        if ('tags' !== $options->source && null !== $options->repository) {
            if (1 !== preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~', $options->repository)) {
                throw $this->exceptions->invalid('GitHub history repository must use owner/name.');
            }
            foreach ($this->github->paginate('/repos/' . $options->repository . '/releases') as $release) {
                if (! is_array($release) || ! isset($release['tag_name'], $release['draft'], $release['prerelease'])
                    || ! is_string($release['tag_name']) || ! is_bool($release['draft']) || ! is_bool(
                        $release['prerelease'],
                    )
                ) {
                    throw $this->exceptions->invalid('GitHub release history returned an invalid record.');
                }
                $version = substr($release['tag_name'], strlen($options->tagPrefix));
                if ($release['draft'] || $release['prerelease'] || ! isset($versions[$version])
                    || $release['tag_name'] !== $versions[$version]['name']
                ) {
                    continue;
                }
                if (isset($published[$version])) {
                    throw $this->exceptions->invalid('Multiple published GitHub releases identify the same Git tag.');
                }
                $published[$version] = $release;
            }
        }
        $all = $document->getReleases();
        foreach ($missing as $version) {
            $tag = $versions[$version];
            $date = 'annotated-tag' === $tag['date_source'] ? $tag['date'] : null;
            $source = null === $date ? null : 'annotated-tag';
            $body = $template->missingNotes() . "\n";
            if (isset($published[$version])) {
                $release = $published[$version];
                $date = $this->publicationDate($release['published_at'] ?? null);
                $source = 'github-release';
                if (null !== ($release['body'] ?? null) && ! is_string($release['body'])) {
                    throw $this->exceptions->invalid('Published GitHub release notes must be a string or null.');
                }
                if (is_string($release['body'] ?? null) && '' !== trim($release['body'])) {
                    $body = $release['body'];
                }
            }
            $new = $this->releases->create($version, $date, $source, $body);
            $position = count($all);
            foreach ($all as $index => $existing) {
                if ($this->stable($existing->getVersion()) && $this->compare($version, $existing->getVersion()) > 0) {
                    $position = $index;

                    break;
                }
            }
            array_splice($all, $position, 0, [$new]);
        }
        $history = $this->documents->create($all, $document->getPrefix(), $document->getReferences());

        return $this->results->create($history, array_values($missing), $current);
    }

    /**
     * Returns the same stable Git baseline as import without fetching or altering history.
     * @param list<array{name:string,sha:string,date:?string,date_source:?string}> $tags caller-provided Git evidence
     */
    public function currentVersion(array $tags, string $tagPrefix = 'v'): string
    {
        return array_key_first($this->versions($tags, $tagPrefix)) ?? '0.0.0';
    }

    /**
     * Resolves exact-prefix stable tags once for both formatting and import planning.
     * @param  list<array{name:string,sha:string,date:?string,date_source:?string}>         $tags Git evidence
     * @return array<string,array{name:string,sha:string,date:?string,date_source:?string}> descending canonical versions
     */
    private function versions(array $tags, string $tagPrefix): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            if (! is_array($tag) || ! isset($tag['name'], $tag['sha']) || ! is_string($tag['name']) || ! is_string(
                $tag['sha'],
            )
                || ! array_key_exists('date', $tag) || ! array_key_exists('date_source', $tag)
                || (null !== $tag['date'] && ! is_string($tag['date']))
                || (null !== $tag['date_source'] && ! is_string($tag['date_source']))
            ) {
                throw $this->exceptions->invalid('Git tag history evidence has an invalid shape.');
            }
            if (! str_starts_with($tag['name'], $tagPrefix)) {
                continue;
            }
            $version = substr($tag['name'], strlen($tagPrefix));
            if (! $this->stable($version)) {
                continue;
            }
            if (isset($versions[$version])) {
                throw $this->exceptions->invalid('Multiple Git tags identify the same canonical stable version.');
            }
            $versions[$version] = $tag;
        }
        uksort($versions, fn(string $left, string $right): int => -$this->compare($left, $right));

        return $versions;
    }

    /** Accepts strict stable semantic identities, including build metadata but no prereleases. */
    private function stable(string $version): bool
    {
        return 1 === preg_match(
            '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/',
            $version,
        );
    }

    /** Compares arbitrary-width numeric components without float conversion or date ordering. */
    private function compare(string $left, string $right): int
    {
        $leftParts = explode('.', explode('+', $left, 2)[0]);
        $rightParts = explode('.', explode('+', $right, 2)[0]);
        foreach ($leftParts as $index => $component) {
            $comparison = strlen($component) <=> strlen($rightParts[$index]);
            if (0 === $comparison) {
                $comparison = strcmp($component, $rightParts[$index]);
            }
            if (0 !== $comparison) {
                return $comparison;
            }
        }

        return strcmp($left, $right);
    }

    /** Validates an explicit ISO timestamp and derives its UTC publication day, never its commit date. */
    private function publicationDate(mixed $timestamp): string
    {
        if (! is_string($timestamp) || 1 !== preg_match(
            '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-](\d{2}):(\d{2}))\z/',
            $timestamp,
            $matches,
        )
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            || (int) $matches[4] > 23 || (int) $matches[5] > 59 || (int) $matches[6] > 59
            || (isset($matches[8]) && ((int) $matches[8] > 23 || (int) $matches[9] > 59))
        ) {
            throw $this->exceptions->invalid('Published GitHub release dates require a valid explicit ISO timestamp.');
        }

        return gmdate('Y-m-d', strtotime($timestamp));
    }
}
