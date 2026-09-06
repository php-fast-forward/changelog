<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Manager;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogDocumentFactoryInterface;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Document\ChangelogReleaseFactoryInterface;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Git\GitRepositoryUrlResolverInterface;
use FastForward\Changelog\Manager\ChangelogManager;
use FastForward\Changelog\Parser\ChangelogParserInterface;
use FastForward\Changelog\Renderer\MarkdownRendererInterface;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ChangelogManager::class)]
#[UsesClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
final class ChangelogManagerTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function loadCreatesADocumentWhenTheFileIsAbsent(): void
    {
        [$manager, $filesystem, , , , $documentFactory] = $this->manager();
        $document = $this->document();
        $filesystem->exists('CHANGELOG.md')->willReturn(false)->shouldBeCalledOnce();
        $documentFactory->create()->willReturn($document)->shouldBeCalledOnce();

        self::assertSame($document, $manager->load('CHANGELOG.md'));
    }

    #[Test]
    public function loadParsesAnExistingFileWithoutRealIo(): void
    {
        [$manager, $filesystem, $parser] = $this->manager();
        $document = $this->document();
        $filesystem->exists('CHANGELOG.md')->willReturn(true)->shouldBeCalledOnce();
        $filesystem->readFile('CHANGELOG.md')->willReturn('markdown')->shouldBeCalledOnce();
        $parser->parse('markdown')->willReturn($document)->shouldBeCalledOnce();

        self::assertSame($document, $manager->load('CHANGELOG.md'));
    }

    #[Test]
    public function addEntryCreatesAMissingReleaseAndPersistsThroughMocks(): void
    {
        [$manager, $filesystem, $parser, $renderer, $git, , $releaseFactory] = $this->manager();
        $document = $this->document();
        $release = new ChangelogRelease('1.0.0', '2026-09-05');
        $this->willLoad($filesystem, $parser, $document);
        $releaseFactory->create('1.0.0', '2026-09-05')->willReturn($release)->shouldBeCalledOnce();
        $this->willPersist($filesystem, $renderer, $git);

        $manager->addEntry('CHANGELOG.md', ChangelogEntryType::Added, 'entry', '1.0.0', '2026-09-05');
    }

    #[Test]
    public function addEntryUpdatesADifferentExistingDate(): void
    {
        [$manager, $filesystem, $parser, $renderer, $git, , $releaseFactory] = $this->manager();
        $release = new ChangelogRelease('1.0.0', '2026-01-01');
        $document = $this->document([$release]);
        $this->willLoad($filesystem, $parser, $document);
        $releaseFactory->create('1.0.0', '2026-09-05')->shouldNotBeCalled();
        $this->willPersist($filesystem, $renderer, $git);

        $manager->addEntry('CHANGELOG.md', ChangelogEntryType::Fixed, 'fix', '1.0.0', '2026-09-05');
    }

    #[Test]
    public function addEntryCreatesAMissingParentBeforeResolvingGit(): void
    {
        [$manager, $filesystem, , $renderer, $git, $documentFactory, $releaseFactory] = $this->manager();
        $document = $this->document();
        $filesystem->exists('/project/missing/CHANGELOG.md')->willReturn(false)->shouldBeCalledOnce();
        $documentFactory->create()->willReturn($document)->shouldBeCalledOnce();
        $releaseFactory->create(Argument::cetera())->shouldNotBeCalled();
        $filesystem->getDirectory('/project/missing/CHANGELOG.md')
            ->willReturn('/project/missing')
            ->shouldBeCalledOnce();
        $filesystem->exists('/project/missing')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->mkdir('/project/missing')->shouldBeCalledOnce();
        $git->resolve('/project/missing')
            ->willReturn('https://example.com/repo')
            ->shouldBeCalledOnce();
        $renderer->render(Argument::type(ChangelogDocument::class), 'https://example.com/repo')
            ->willReturn('rendered')
            ->shouldBeCalledOnce();
        $filesystem->dumpFile('/project/missing/CHANGELOG.md', 'rendered')->shouldBeCalledOnce();

        $manager->addEntry(
            '/project/missing/CHANGELOG.md',
            ChangelogEntryType::Added,
            'entry',
        );
    }

    #[Test]
    public function promoteRejectsAnEmptyUnreleasedSection(): void
    {
        [$manager, $filesystem, $parser] = $this->manager();
        $this->willLoad($filesystem, $parser, $this->document());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CHANGELOG.md does not contain unreleased entries to promote.');

        $manager->promote('CHANGELOG.md', '1.0.0', '2026-09-05');
    }

    #[Test]
    public function promoteCreatesReleaseValuesAndPersistsThePromotion(): void
    {
        [$manager, $filesystem, $parser, $renderer, $git, , $releaseFactory] = $this->manager();
        $unreleased = (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))
            ->withEntry(ChangelogEntryType::Added, 'entry');
        $document = new ChangelogDocument([$unreleased]);
        $promoted = new ChangelogRelease('1.0.0', '2026-09-05', $unreleased->getEntries());
        $empty = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $this->willLoad($filesystem, $parser, $document);
        $releaseFactory->create('1.0.0', '2026-09-05', $unreleased->getEntries())->willReturn($promoted)->shouldBeCalledOnce();
        $releaseFactory->create(ChangelogDocument::UNRELEASED_VERSION)->willReturn($empty)->shouldBeCalledOnce();
        $this->willPersist($filesystem, $renderer, $git);

        $manager->promote('CHANGELOG.md', '1.0.0', '2026-09-05');
    }

    #[Test]
    public function inferNextVersionRejectsAnEmptyUnreleasedSection(): void
    {
        [$manager, $filesystem, $parser] = $this->manager();
        $this->willLoad($filesystem, $parser, $this->document());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain unreleased entries to infer a version from');

        $manager->inferNextVersion('CHANGELOG.md');
    }

    #[Test]
    public function inferNextVersionRejectsInvalidSemanticVersions(): void
    {
        [$manager, $filesystem, $parser] = $this->manager();
        $this->willLoad($filesystem, $parser, $this->documentWithEntry(ChangelogEntryType::Fixed));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot infer a version from invalid semantic version "latest".');

        $manager->inferNextVersion('CHANGELOG.md', 'latest');
    }

    #[Test]
    #[TestWith([ChangelogEntryType::Removed, 'v1.2.3', '2.0.0'])]
    #[TestWith([ChangelogEntryType::Deprecated, '1.2.3', '2.0.0'])]
    #[TestWith([ChangelogEntryType::Added, 'V1.2.3', '1.3.0'])]
    #[TestWith([ChangelogEntryType::Changed, '1.2.3', '1.3.0'])]
    #[TestWith([ChangelogEntryType::Fixed, '1.2.3', '1.2.4'])]
    #[TestWith([ChangelogEntryType::Security, '0.0.0', '0.0.1'])]
    public function inferNextVersionAppliesCategoryPrecedence(
        ChangelogEntryType $type,
        string $currentVersion,
        string $expected,
    ): void {
        [$manager, $filesystem, $parser] = $this->manager();
        $this->willLoad($filesystem, $parser, $this->documentWithEntry($type));

        self::assertSame($expected, $manager->inferNextVersion('CHANGELOG.md', $currentVersion));
    }

    #[Test]
    public function inferNextVersionUsesLatestPublishedVersionOrZeroFallback(): void
    {
        [$manager, $filesystem, $parser] = $this->manager();
        $document = $this->documentWithEntry(ChangelogEntryType::Fixed, [new ChangelogRelease('1.2.3')]);
        $this->willLoad($filesystem, $parser, $document);
        self::assertSame('1.2.4', $manager->inferNextVersion('CHANGELOG.md'));

        [$fallbackManager, $fallbackFilesystem, $fallbackParser] = $this->manager();
        $this->willLoad($fallbackFilesystem, $fallbackParser, $this->documentWithEntry(ChangelogEntryType::Fixed));
        self::assertSame('0.0.1', $fallbackManager->inferNextVersion('CHANGELOG.md'));
    }

    #[Test]
    public function renderReleaseNotesRejectsAMissingVersion(): void
    {
        [$manager, $filesystem, $parser] = $this->manager();
        $this->willLoad($filesystem, $parser, $this->document());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CHANGELOG.md does not contain a [9.9.9] section.');

        $manager->renderReleaseNotes('CHANGELOG.md', '9.9.9');
    }

    #[Test]
    public function renderReleaseNotesDelegatesToTheRenderer(): void
    {
        [$manager, $filesystem, $parser, $renderer] = $this->manager();
        $release = new ChangelogRelease('1.0.0');
        $this->willLoad($filesystem, $parser, $this->document([$release]));
        $renderer->renderReleaseBody($release)->willReturn('notes')->shouldBeCalledOnce();

        self::assertSame('notes', $manager->renderReleaseNotes('CHANGELOG.md', '1.0.0'));
    }

    /**
     * @return array{ChangelogManager, ObjectProphecy<PackageFilesystemInterface>, ObjectProphecy<ChangelogParserInterface>, ObjectProphecy<MarkdownRendererInterface>, ObjectProphecy<GitRepositoryUrlResolverInterface>, ObjectProphecy<ChangelogDocumentFactoryInterface>, ObjectProphecy<ChangelogReleaseFactoryInterface>}
     */
    private function manager(): array
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $renderer = $this->prophesize(MarkdownRendererInterface::class);
        $git = $this->prophesize(GitRepositoryUrlResolverInterface::class);
        $documentFactory = $this->prophesize(ChangelogDocumentFactoryInterface::class);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);

        return [
            new ChangelogManager(
                $filesystem->reveal(),
                $parser->reveal(),
                $renderer->reveal(),
                $git->reveal(),
                $documentFactory->reveal(),
                $releaseFactory->reveal(),
            ),
            $filesystem,
            $parser,
            $renderer,
            $git,
            $documentFactory,
            $releaseFactory,
        ];
    }

    /**
     * @param ObjectProphecy<PackageFilesystemInterface> $filesystem
     * @param ObjectProphecy<ChangelogParserInterface> $parser
     */
    private function willLoad(ObjectProphecy $filesystem, ObjectProphecy $parser, ChangelogDocument $document): void
    {
        $filesystem->exists('CHANGELOG.md')->willReturn(true);
        $filesystem->readFile('CHANGELOG.md')->willReturn('stored');
        $parser->parse('stored')->willReturn($document);
    }

    /**
     * @param ObjectProphecy<PackageFilesystemInterface> $filesystem
     * @param ObjectProphecy<MarkdownRendererInterface> $renderer
     * @param ObjectProphecy<GitRepositoryUrlResolverInterface> $git
     */
    private function willPersist(ObjectProphecy $filesystem, ObjectProphecy $renderer, ObjectProphecy $git): void
    {
        $filesystem->getDirectory('CHANGELOG.md')->willReturn('/project');
        $filesystem->exists('/project')->willReturn(true);
        $filesystem->mkdir(Argument::any())->shouldNotBeCalled();
        $git->resolve('/project')->willReturn('https://example.com/repo');
        $renderer->render(Argument::type(ChangelogDocument::class), 'https://example.com/repo')->willReturn('rendered');
        $filesystem->dumpFile('CHANGELOG.md', 'rendered')->shouldBeCalledOnce();
    }

    /**
     * @param list<ChangelogRelease> $published
     */
    private function document(array $published = []): ChangelogDocument
    {
        return new ChangelogDocument([new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION), ...$published]);
    }

    /**
     * @param list<ChangelogRelease> $published
     */
    private function documentWithEntry(ChangelogEntryType $type, array $published = []): ChangelogDocument
    {
        $unreleased = (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))->withEntry($type, 'entry');

        return new ChangelogDocument([$unreleased, ...$published]);
    }
}
