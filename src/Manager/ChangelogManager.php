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

namespace FastForward\Changelog\Manager;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogDocumentFactoryInterface;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Document\ChangelogReleaseFactoryInterface;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Git\GitRepositoryUrlResolverInterface;
use FastForward\Changelog\Parser\ChangelogParserInterface;
use FastForward\Changelog\Renderer\MarkdownRendererInterface;
use RuntimeException;

/**
 * Coordinates changelog loading, mutation, version inference, and persistence.
 *
 * The manager MUST delegate I/O, parsing, rendering, URL resolution, and value
 * construction to injected contracts so the workflow remains unit-testable.
 */
final readonly class ChangelogManager implements ChangelogManagerInterface
{
    /**
     * Composes services used to load, transform, and persist changelogs.
     *
     * @param PackageFilesystemInterface $filesystem reads, writes, and resolves paths
     * @param ChangelogParserInterface $parser parses stored Markdown
     * @param MarkdownRendererInterface $renderer renders persisted Markdown
     * @param GitRepositoryUrlResolverInterface $gitRepositoryUrlResolver resolves reference-link origins
     * @param ChangelogDocumentFactoryInterface $documentFactory creates empty documents
     * @param ChangelogReleaseFactoryInterface $releaseFactory creates new release values
     */
    public function __construct(
        private PackageFilesystemInterface $filesystem,
        private ChangelogParserInterface $parser,
        private MarkdownRendererInterface $renderer,
        private GitRepositoryUrlResolverInterface $gitRepositoryUrlResolver,
        private ChangelogDocumentFactoryInterface $documentFactory,
        private ChangelogReleaseFactoryInterface $releaseFactory,
    ) {}

    /**
     * Adds an entry to Unreleased or to a named published release.
     */
    public function addEntry(
        string $file,
        ChangelogEntryType $type,
        string $message,
        string $version = ChangelogDocument::UNRELEASED_VERSION,
        ?string $date = null,
    ): void {
        $document = $this->load($file);
        $release = $document->getRelease($version) ?? $this->releaseFactory->create($version, $date);

        if (null !== $date && $release->getDate() !== $date) {
            $release = $release->withDate($date);
        }

        $this->persist($file, $document->withRelease($release->withEntry($type, $message)));
    }

    /**
     * Promotes Unreleased entries into the requested version and date.
     *
     * @throws RuntimeException when Unreleased contains no entries
     */
    public function promote(string $file, string $version, string $date): void
    {
        $document = $this->load($file);

        if (! $document->getUnreleased()->hasEntries()) {
            throw new RuntimeException(\sprintf('%s does not contain unreleased entries to promote.', $file));
        }

        $promoted = $this->releaseFactory->create($version, $date, $document->getUnreleased()->getEntries());
        $emptyUnreleased = $this->releaseFactory->create(ChangelogDocument::UNRELEASED_VERSION);

        $this->persist($file, $document->promoteUnreleased($promoted, $emptyUnreleased));
    }

    /**
     * Infers the next semantic version from the categories in Unreleased.
     *
     * Removed or Deprecated entries require a major bump; Added or Changed
     * entries require a minor bump; remaining supported entries require a patch.
     *
     * @throws RuntimeException when Unreleased is empty or the base version is invalid
     */
    public function inferNextVersion(string $file, ?string $currentVersion = null): string
    {
        $document = $this->load($file);
        $unreleased = $document->getUnreleased();

        if (! $unreleased->hasEntries()) {
            throw new RuntimeException(\sprintf('%s does not contain unreleased entries to infer a version from.', $file));
        }

        $currentVersion = ltrim(
            $currentVersion ?? $document->getLatestPublishedRelease()?->getVersion() ?? '0.0.0',
            'vV',
        );
        if (1 !== preg_match('/^(?<major>0|[1-9]\d*)\.(?<minor>0|[1-9]\d*)\.(?<patch>0|[1-9]\d*)$/', $currentVersion, $matches)) {
            throw new RuntimeException(\sprintf('Cannot infer a version from invalid semantic version "%s".', $currentVersion));
        }

        $major = (int) $matches['major'];
        $minor = (int) $matches['minor'];
        $patch = (int) $matches['patch'];

        if ([] !== $unreleased->getEntriesFor(ChangelogEntryType::Removed)
            || [] !== $unreleased->getEntriesFor(ChangelogEntryType::Deprecated)
        ) {
            return \sprintf('%d.0.0', $major + 1);
        }

        if ([] !== $unreleased->getEntriesFor(ChangelogEntryType::Added)
            || [] !== $unreleased->getEntriesFor(ChangelogEntryType::Changed)
        ) {
            return \sprintf('%d.%d.0', $major, $minor + 1);
        }

        return \sprintf('%d.%d.%d', $major, $minor, $patch + 1);
    }

    /**
     * Renders the body for one named release.
     *
     * @throws RuntimeException when the requested release does not exist
     */
    public function renderReleaseNotes(string $file, string $version): string
    {
        $release = $this->load($file)->getRelease($version);

        if (! $release instanceof ChangelogRelease) {
            throw new RuntimeException(\sprintf('%s does not contain a [%s] section.', $file, $version));
        }

        return $this->renderer->renderReleaseBody($release);
    }

    /**
     * Loads a document or creates an empty document when the file is absent.
     */
    public function load(string $file): ChangelogDocument
    {
        if (! $this->filesystem->exists($file)) {
            return $this->documentFactory->create();
        }

        return $this->parser->parse($this->filesystem->readFile($file));
    }

    /**
     * Persists rendered Markdown with repository references when available.
     *
     * The parent directory MUST exist before it is used as a Git working
     * directory. A missing parent SHALL be created before repository discovery
     * so a nested changelog can still receive comparison links.
     */
    private function persist(string $file, ChangelogDocument $document): void
    {
        $directory = $this->filesystem->getDirectory($file);

        if (! $this->filesystem->exists($directory)) {
            $this->filesystem->mkdir($directory);
        }

        $repositoryUrl = $this->gitRepositoryUrlResolver->resolve($directory);

        $this->filesystem->dumpFile(
            $file,
            $this->renderer->render(
                $document,
                $repositoryUrl,
            ),
        );
    }
}
