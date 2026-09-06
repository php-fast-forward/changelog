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

namespace FastForward\Changelog\Renderer;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryTypesInterface;

use function Safe\preg_match;
use function Safe\preg_replace;
use function explode;
use function implode;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Renders deterministic Keep a Changelog Markdown and release-note bodies.
 *
 * Output MUST end with one newline. Repository references MUST preserve an
 * existing leading v/V and normalize supported Git transport URLs.
 */
final readonly class MarkdownRenderer implements MarkdownRendererInterface
{
    private const string INTRODUCTION = "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\nThe format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),\nand this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).";

    /**
     * Initializes rendering with the canonical changelog section order.
     *
     * @param ChangelogEntryTypesInterface $entryTypes supplies canonical section order
     */
    public function __construct(
        private ChangelogEntryTypesInterface $entryTypes,
    ) {}

    /**
     * Renders a complete changelog and optional repository references.
     */
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

    /**
     * Renders one release body without its version heading.
     */
    public function renderReleaseBody(ChangelogRelease $release): string
    {
        return implode("\n", \array_slice($this->renderRelease($release), 2)) . "\n";
    }

    /**
     * Renders a release heading and non-empty category sections.
     *
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

        foreach ($this->entryTypes->ordered() as $type) {
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
     * Renders compare and release references for published versions.
     *
     * @return list<string>
     */
    private function renderReferences(ChangelogDocument $document, ?string $repositoryUrl): array
    {
        $normalizedRepositoryUrl = $this->normalizeRepositoryUrl($repositoryUrl);

        if (null === $normalizedRepositoryUrl) {
            $references = $document->getReferences();

            return [] === $references ? [] : ['', ...$references];
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

    /**
     * Resolves the Git tag for a release without double-prefixing v/V.
     */
    private function resolveTag(ChangelogRelease $release): string
    {
        $version = $release->getVersion();

        return str_starts_with($version, 'v') || str_starts_with($version, 'V')
            ? $version
            : 'v' . $version;
    }

    /**
     * Normalizes supported SSH and HTTPS repository URLs.
     *
     * The returned public URL MUST remove userinfo so credentials can never be
     * copied from a Git remote into tracked Markdown.
     */
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

        $repositoryUrl = preg_replace('~^(https?://)[^/@]+@~i', '$1', $repositoryUrl);

        if (str_ends_with($repositoryUrl, '.git')) {
            $repositoryUrl = substr($repositoryUrl, 0, -4);
        }

        return rtrim($repositoryUrl, '/');
    }
}
