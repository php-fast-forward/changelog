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

namespace FastForward\Changelog\Tests\Manager;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Filesystem\PackageFilesystem;
use FastForward\Changelog\Git\GitRepositoryUrlResolver;
use FastForward\Changelog\Manager\ChangelogManager;
use FastForward\Changelog\Parser\ChangelogParser;
use FastForward\Changelog\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function Safe\mkdir;

#[CoversClass(ChangelogManager::class)]
final class ChangelogManagerTest extends TestCase
{
    private string $temporaryDirectory;

    private string $changelogFile;

    private ChangelogManager $changelogManager;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/fast-forward-changelog-tests-' . uniqid();
        mkdir($this->temporaryDirectory, 0o777, true);

        $filesystem = new PackageFilesystem(new Filesystem());
        $this->changelogFile = $this->temporaryDirectory . '/CHANGELOG.md';
        $this->changelogManager = new ChangelogManager(
            $filesystem,
            new ChangelogParser(),
            new MarkdownRenderer(),
            new GitRepositoryUrlResolver($filesystem),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    #[Test]
    public function addEntryWillCreateTheManagedChangelogWhenNeeded(): void
    {
        $this->changelogManager->addEntry(
            $this->changelogFile,
            ChangelogEntryType::Added,
            'Ship changelog automation',
        );

        $contents = file_get_contents($this->changelogFile);

        self::assertIsString($contents);
        self::assertStringContainsString('## [Unreleased]', $contents);
        self::assertStringContainsString('- Ship changelog automation', $contents);
    }

    #[Test]
    public function promoteWillPersistThePublishedReleaseWhenUnreleasedEntriesExist(): void
    {
        $this->changelogManager->addEntry(
            $this->changelogFile,
            ChangelogEntryType::Added,
            'Prepare release automation',
        );

        $this->changelogManager->promote($this->changelogFile, '1.0.0', '2026-04-29');
        $document = $this->changelogManager->load($this->changelogFile);

        self::assertFalse($document->getUnreleased()->hasEntries());
        self::assertSame('2026-04-29', $document->getRelease('1.0.0')?->getDate());
        self::assertSame(
            ['Prepare release automation'],
            $document->getRelease('1.0.0')?->getEntriesFor(ChangelogEntryType::Added),
        );
    }

    #[Test]
    public function inferNextVersionWillUseTheCurrentPublishedVersionAsTheBumpBase(): void
    {
        $this->changelogManager->addEntry($this->changelogFile, ChangelogEntryType::Added, 'Initial release');
        $this->changelogManager->promote($this->changelogFile, '1.0.0', '2026-04-29');
        $this->changelogManager->addEntry($this->changelogFile, ChangelogEntryType::Changed, 'Expand release notes');

        self::assertSame('1.1.0', $this->changelogManager->inferNextVersion($this->changelogFile));
    }

    #[Test]
    public function inferNextVersionWillThrowWhenNoUnreleasedEntriesExist(): void
    {
        $filesystem = new PackageFilesystem(new Filesystem());
        $filesystem->dumpFile(
            $this->changelogFile,
            (new MarkdownRenderer())->render(ChangelogDocument::create()),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->changelogFile . ' does not contain unreleased entries to infer a version from.');

        $this->changelogManager->inferNextVersion($this->changelogFile);
    }

    #[Test]
    public function renderReleaseNotesWillReturnTheRenderedBodyForAPublishedRelease(): void
    {
        $this->changelogManager->addEntry($this->changelogFile, ChangelogEntryType::Added, 'Ship release notes');
        $this->changelogManager->promote($this->changelogFile, '1.0.0', '2026-04-29');

        $releaseNotes = $this->changelogManager->renderReleaseNotes($this->changelogFile, '1.0.0');

        self::assertStringContainsString('### Added', $releaseNotes);
        self::assertStringContainsString('- Ship release notes', $releaseNotes);
        self::assertStringNotContainsString('## [1.0.0]', $releaseNotes);
    }
}
