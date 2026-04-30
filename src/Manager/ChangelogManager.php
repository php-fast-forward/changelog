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

namespace FastForward\Changelog\Manager;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Filesystem\PackageFilesystem;
use FastForward\Changelog\Git\GitRepositoryUrlResolver;
use FastForward\Changelog\Parser\ChangelogParser;
use FastForward\Changelog\Renderer\MarkdownRenderer;
use RuntimeException;

final readonly class ChangelogManager
{
    public function __construct(
        private PackageFilesystem $filesystem,
        private ChangelogParser $parser,
        private MarkdownRenderer $renderer,
        private GitRepositoryUrlResolver $gitRepositoryUrlResolver,
    ) {}

    public function addEntry(
        string $file,
        ChangelogEntryType $type,
        string $message,
        string $version = ChangelogDocument::UNRELEASED_VERSION,
        ?string $date = null,
    ): void {
        $document = $this->load($file);
        $release = $document->getRelease($version) ?? new ChangelogRelease($version, $date);

        if (null !== $date && $release->getDate() !== $date) {
            $release = $release->withDate($date);
        }

        $this->persist($file, $document->withRelease($release->withEntry($type, $message)));
    }

    public function promote(string $file, string $version, string $date): void
    {
        $document = $this->load($file);

        if (! $document->getUnreleased()->hasEntries()) {
            throw new RuntimeException(\sprintf('%s does not contain unreleased entries to promote.', $file));
        }

        $this->persist($file, $document->promoteUnreleased($version, $date));
    }

    public function inferNextVersion(string $file, ?string $currentVersion = null): string
    {
        $document = $this->load($file);
        $unreleased = $document->getUnreleased();

        if (! $unreleased->hasEntries()) {
            throw new RuntimeException(\sprintf('%s does not contain unreleased entries to infer a version from.', $file));
        }

        $currentVersion ??= $document->getLatestPublishedRelease()?->getVersion() ?? '0.0.0';
        [$major, $minor, $patch] = array_map(intval(...), explode('.', $currentVersion));

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

    public function renderReleaseNotes(string $file, string $version): string
    {
        $release = $this->load($file)->getRelease($version);

        if (! $release instanceof ChangelogRelease) {
            throw new RuntimeException(\sprintf('%s does not contain a [%s] section.', $file, $version));
        }

        return $this->renderer->renderReleaseBody($release);
    }

    public function load(string $file): ChangelogDocument
    {
        if (! $this->filesystem->exists($file)) {
            return ChangelogDocument::create();
        }

        return $this->parser->parse($this->filesystem->readFile($file));
    }

    private function persist(string $file, ChangelogDocument $document): void
    {
        $this->filesystem->dumpFile(
            $file,
            $this->renderer->render(
                $document,
                $this->gitRepositoryUrlResolver->resolve($this->filesystem->getDirectory($file)),
            ),
        );
    }
}
