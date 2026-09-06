<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Checker;

use FastForward\Changelog\Checker\UnreleasedEntryChecker;
use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Git\GitFileNotFoundException;
use FastForward\Changelog\Git\GitFileReaderInterface;
use FastForward\Changelog\Parser\ChangelogParserInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnreleasedEntryChecker::class)]
#[UsesClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
final class UnreleasedEntryCheckerTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function missingCurrentFileHasNoPendingChanges(): void
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $git = $this->prophesize(GitFileReaderInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $filesystem->exists('/project/CHANGELOG.md')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->readFile('/project/CHANGELOG.md')->shouldNotBeCalled();

        self::assertFalse((new UnreleasedEntryChecker($filesystem->reveal(), $git->reveal(), $parser->reveal()))
            ->hasPendingChanges('/project/CHANGELOG.md'));
    }

    #[Test]
    public function emptyCurrentUnreleasedHasNoPendingChanges(): void
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $git = $this->prophesize(GitFileReaderInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $document = $this->document([]);
        $filesystem->exists('CHANGELOG.md')->willReturn(true)->shouldBeCalledOnce();
        $filesystem->readFile('CHANGELOG.md')->willReturn('current')->shouldBeCalledOnce();
        $parser->parse('current')->willReturn($document)->shouldBeCalledOnce();

        self::assertFalse((new UnreleasedEntryChecker($filesystem->reveal(), $git->reveal(), $parser->reveal()))
            ->hasPendingChanges('CHANGELOG.md'));
    }

    #[Test]
    public function currentEntriesWithoutABaselineArePending(): void
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $git = $this->prophesize(GitFileReaderInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $filesystem->exists('CHANGELOG.md')->willReturn(true);
        $filesystem->readFile('CHANGELOG.md')->willReturn('current');
        $parser->parse('current')->willReturn($this->document(['entry', 'entry']));
        $git->show('main', 'CHANGELOG.md', '/project')->shouldNotBeCalled();

        self::assertTrue((new UnreleasedEntryChecker($filesystem->reveal(), $git->reveal(), $parser->reveal()))
            ->hasPendingChanges('CHANGELOG.md'));
    }

    #[Test]
    public function missingBaselineFileMakesCurrentEntriesPending(): void
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $git = $this->prophesize(GitFileReaderInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $filesystem->exists('/project/CHANGELOG.md')->willReturn(true);
        $filesystem->readFile('/project/CHANGELOG.md')->willReturn('current');
        $parser->parse('current')->willReturn($this->document(['entry']));
        $git->show('main', '/project/CHANGELOG.md', '/project')->willThrow(new GitFileNotFoundException('missing'));
        $checker = new UnreleasedEntryChecker($filesystem->reveal(), $git->reveal(), $parser->reveal());

        self::assertTrue($checker->hasPendingChanges('/project/CHANGELOG.md', 'main', '/project'));
    }

    #[Test]
    public function baselineComparisonOnlyAcceptsNewUniqueEntries(): void
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $git = $this->prophesize(GitFileReaderInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $filesystem->exists('/project/CHANGELOG.md')->willReturn(true);
        $filesystem->readFile('/project/CHANGELOG.md')->willReturn('current');
        $filesystem->getDirectory('/project/CHANGELOG.md')->willReturn('/project')->shouldBeCalledOnce();
        $parser->parse('current')->willReturn($this->document(['old', 'new']));
        $git->show('main', '/project/CHANGELOG.md', '/project')->willReturn('baseline')->shouldBeCalledOnce();
        $parser->parse('baseline')->willReturn($this->document(['old']))->shouldBeCalledOnce();
        $checker = new UnreleasedEntryChecker($filesystem->reveal(), $git->reveal(), $parser->reveal());

        self::assertTrue($checker->hasPendingChanges('/project/CHANGELOG.md', 'main'));
    }

    #[Test]
    public function identicalBaselineHasNoPendingChanges(): void
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $git = $this->prophesize(GitFileReaderInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $filesystem->exists('CHANGELOG.md')->willReturn(true);
        $filesystem->readFile('CHANGELOG.md')->willReturn('current');
        $parser->parse('current')->willReturn($this->document(['same']));
        $git->show('main', 'CHANGELOG.md', '/project')->willReturn('baseline');
        $parser->parse('baseline')->willReturn($this->document(['same']));
        $checker = new UnreleasedEntryChecker($filesystem->reveal(), $git->reveal(), $parser->reveal());

        self::assertFalse($checker->hasPendingChanges('CHANGELOG.md', 'main', '/project'));
    }

    #[Test]
    public function movingAnEntryToAnotherCategoryIsAPendingChange(): void
    {
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $git = $this->prophesize(GitFileReaderInterface::class);
        $parser = $this->prophesize(ChangelogParserInterface::class);
        $filesystem->exists('CHANGELOG.md')->willReturn(true);
        $filesystem->readFile('CHANGELOG.md')->willReturn('current');
        $parser->parse('current')->willReturn($this->document(['same'], ChangelogEntryType::Security));
        $git->show('main', 'CHANGELOG.md', '/project')->willReturn('baseline');
        $parser->parse('baseline')->willReturn($this->document(['same'], ChangelogEntryType::Added));
        $checker = new UnreleasedEntryChecker($filesystem->reveal(), $git->reveal(), $parser->reveal());

        self::assertTrue($checker->hasPendingChanges('CHANGELOG.md', 'main', '/project'));
    }

    /**
     * @param list<string> $entries
     */
    private function document(
        array $entries,
        ChangelogEntryType $type = ChangelogEntryType::Added,
    ): ChangelogDocument
    {
        $release = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION, null, [
            $type->value => $entries,
        ]);

        return new ChangelogDocument([$release]);
    }
}
