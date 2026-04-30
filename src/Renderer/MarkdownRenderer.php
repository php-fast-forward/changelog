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

namespace FastForward\Changelog\Renderer;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;

use function Safe\preg_match;
use function explode;
use function implode;
use function rtrim;
use function str_ends_with;
use function substr;
use function trim;

final readonly class MarkdownRenderer
{
    private const string INTRODUCTION = "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\nThe format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),\nand this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).";

    public function render(ChangelogDocument $document, ?string $repositoryUrl = null): string
    {
        $lines = explode("\n", self::INTRODUCTION);

        foreach ($document->getReleases() as $release) {
            if ('' !== $lines[array_key_last($lines)]) {
                $lines[] = '';
            }

            $lines = [...$lines, ...$this->renderRelease($release)];
        }

        $references = $this->renderReferences($document, $repositoryUrl);

        if ([] !== $references) {
            if ('' !== $lines[array_key_last($lines)]) {
                $lines[] = '';
            }

            $lines = [...$lines, ...$references];
        }

        return implode("\n", $lines) . "\n";
    }

    public function renderReleaseBody(ChangelogRelease $release): string
    {
        return implode("\n", \array_slice($this->renderRelease($release), 2)) . "\n";
    }

    /**
     * @return list<string>
     */
    private function renderRelease(ChangelogRelease $release): array
    {
        $heading = $release->isUnreleased()
            ? \sprintf('## [%s]', ChangelogDocument::UNRELEASED_VERSION)
            : (null === $release->getDate()
                ? \sprintf('## [%s]', $release->getVersion())
                : \sprintf('## [%s] - %s', $release->getVersion(), $release->getDate()));

        $lines = [$heading, ''];
        $renderedSections = 0;

        foreach (ChangelogEntryType::ordered() as $type) {
            $sectionEntries = $release->getEntriesFor($type);

            if ([] === $sectionEntries) {
                continue;
            }

            if (0 < $renderedSections) {
                $lines[] = '';
            }

            $lines[] = '### ' . $type->value;
            $lines[] = '';

            foreach ($sectionEntries as $entry) {
                $lines[] = '- ' . $entry;
            }

            ++$renderedSections;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderReferences(ChangelogDocument $document, ?string $repositoryUrl): array
    {
        $normalizedRepositoryUrl = $this->normalizeRepositoryUrl($repositoryUrl);

        if (null === $normalizedRepositoryUrl) {
            return [];
        }

        $published = array_values(array_filter(
            $document->getReleases(),
            static fn(ChangelogRelease $release): bool => ! $release->isUnreleased(),
        ));

        if ([] === $published) {
            return [];
        }

        $references = [
            \sprintf(
                '[unreleased]: %s/compare/%s...HEAD',
                $normalizedRepositoryUrl,
                $this->resolveTag($published[0]),
            ),
        ];

        foreach ($published as $index => $release) {
            $references[] = isset($published[$index + 1])
                ? \sprintf(
                    '[%s]: %s/compare/%s...%s',
                    $release->getVersion(),
                    $normalizedRepositoryUrl,
                    $this->resolveTag($published[$index + 1]),
                    $this->resolveTag($release),
                )
                : \sprintf(
                    '[%s]: %s/releases/tag/%s',
                    $release->getVersion(),
                    $normalizedRepositoryUrl,
                    $this->resolveTag($release),
                );
        }

        return ['', ...$references];
    }

    private function resolveTag(ChangelogRelease $release): string
    {
        return 'v' . $release->getVersion();
    }

    private function normalizeRepositoryUrl(?string $repositoryUrl): ?string
    {
        if (null === $repositoryUrl) {
            return null;
        }

        $repositoryUrl = trim($repositoryUrl);

        if ('' === $repositoryUrl) {
            return null;
        }

        if (1 === preg_match('~^git@(?<host>[^:]+):(?<path>.+)$~', $repositoryUrl, $matches)) {
            $repositoryUrl = 'https://' . $matches['host'] . '/' . $matches['path'];
        }

        if (1 === preg_match('~^ssh://git@(?<host>[^/]+)/(?<path>.+)$~', $repositoryUrl, $matches)) {
            $repositoryUrl = 'https://' . $matches['host'] . '/' . $matches['path'];
        }

        if (str_ends_with($repositoryUrl, '.git')) {
            $repositoryUrl = substr($repositoryUrl, 0, -4);
        }

        return rtrim($repositoryUrl, '/');
    }
}
