<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Console\Command\NotesCommand;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Tests\Console\PlanFixtureTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

#[CoversClass(NotesCommand::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
final class NotesCommandTest extends TestCase
{
    use PlanFixtureTrait;

    #[Test]
    #[TestWith(['1.0.0'])]
    #[TestWith(['9.0.0-rc.1'])]
    public function explicitVersionWritesOnlyExactRawNotesToStdout(string $version): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $document = new HistoryDocument();
        $files->expects(self::once())->method('read')->with('/consumer/CHANGELOG.md')->willReturn('central');
        $history->expects(self::once())->method('parse')->with('central')->willReturn($document);
        $history->expects(self::once())->method('notes')->with($document, $version)->willReturn("Raw <info>notes</info>\n\n");
        $git->expects(self::never())->method('tags');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects(self::never())->method('write');
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('write')->with("Raw <info>notes</info>\n\n", false, OutputInterface::OUTPUT_RAW);
        $output->expects(self::never())->method('writeln');
        self::assertSame(0, $command($this->settings(), $output, $version));
    }

    #[Test]
    #[DataProvider('maintainedVersionOrders')]
    public function defaultVersionUsesHighestMaintainedStableSemVerBeforeGitTags(array $versions, string $expected): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $files->expects(self::once())->method('read')->with('/consumer/CHANGELOG.md')->willReturn('central');
        $releases = array_map(static fn(string $version): HistoryRelease => new HistoryRelease($version), $versions);
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument($releases));
        $history->expects(self::once())->method('notes')->with(self::anything(), $expected)->willReturn('notes');
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        self::assertSame(0, $command($this->settings(), $this->createStub(OutputInterface::class)));
    }

    /** Release ordering cannot change the selected stable identity, including arbitrary-width numeric components. */
    public static function maintainedVersionOrders(): iterable
    {
        yield 'oldest first' => [['1.0.0', '2.0.0'], '2.0.0'];
        yield 'newest first' => [['2.0.0', '1.0.0'], '2.0.0'];
        yield 'custom order with pending and prerelease first' => [['unreleased', '99.0.0-rc.1', '1.10.0', '9.0.0', '2.0.0', '5.0.0'], '9.0.0'];
        yield 'numeric minor width' => [['1.9.999', '1.10.0'], '1.10.0'];
        yield 'numeric patch width' => [['1.0.9', '1.0.10'], '1.0.10'];
        yield 'wide major' => [['18446744073709551615.0.0', '18446744073709551616.0.0'], '18446744073709551616.0.0'];
        yield 'wide minor' => [['1.18446744073709551615.0', '1.18446744073709551616.0'], '1.18446744073709551616.0'];
        yield 'wide patch' => [['1.0.18446744073709551615', '1.0.18446744073709551616'], '1.0.18446744073709551616'];
        yield 'build tie forward order' => [['1.0.0', '1.0.0+build.a', '1.0.0+build.b'], '1.0.0+build.b'];
        yield 'build tie reverse order' => [['1.0.0+build.b', '1.0.0+build.a', '1.0.0'], '1.0.0+build.b'];
        yield 'invalid stable identity is not selected' => [['01.0.0', '1.1.0', 'unreleased'], '1.1.0'];
        yield 'stable zero is a maintained release' => [['1.0.0-alpha', '0.0.0'], '0.0.0'];
    }

    #[Test]
    #[DataProvider('baselineSources')]
    public function fallbackUsesOnlyObservedStableGitEvidence(bool $repository, array $versions): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $files->expects(self::once())->method('read')->with('/consumer/CHANGELOG.md')->willReturn('central');
        $git->expects(self::once())->method('isRepository')->with('/consumer')->willReturn($repository);
        $git->expects($repository ? self::once() : self::never())->method('resolveRef')->with('/consumer', 'HEAD')->willReturn(str_repeat('b', 40));
        $tags = $repository ? [['name' => 'v2.0.0','sha' => str_repeat('a', 40),'date' => null,'date_source' => null]] : [];
        $git->expects($repository ? self::once() : self::never())->method('tags')->willReturn($tags);
        $git->expects($repository ? self::once() : self::never())->method('isAncestor')->with('/consumer', str_repeat('a', 40), str_repeat('b', 40))->willReturn(true);
        $expected = $repository ? '2.0.0' : '0.0.0';
        $importer->expects(self::once())->method('currentVersion')->with($tags, 'v')->willReturn($expected);
        $releases = array_map(static fn(string $version): HistoryRelease => new HistoryRelease($version), $versions);
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument($releases));
        $history->expects(self::once())->method('notes')->with(self::anything(), $expected)->willReturn('exact notes');
        self::assertSame(0, $command($this->settings(), $this->createStub(OutputInterface::class)));
    }

    /** Empty and prerelease-only maintained histories use the reachable stable-tag baseline. */
    public static function baselineSources(): array
    {
        return [[true, []], [true, ['unreleased', '9.0.0-rc.1']], [false, ['unreleased', '1.0.0-beta.2']]];
    }

    #[Test]
    public function optionalOutputUsesManagedWriterWithExactBytes(): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose(...$this->outputLocks(true));
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects(self::exactly(2))->method('read')->willReturnMap([['/consumer/CHANGELOG.md','central'],['/consumer/notes/release.md',null]]);
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument());
        $history->expects(self::once())->method('notes')->willReturn("exact\n");
        $paths->expects(self::once())->method('isAbsolute')->with('notes/release.md')->willReturn(false);
        $files->expects(self::once())->method('write')->with('/consumer/notes/release.md', "exact\n");
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::never())->method('write');
        $output->expects(self::never())->method('writeln');
        self::assertSame(0, $command($this->settings(), $output, '1.0.0', 'notes/release.md'));
    }

    #[Test]
    #[DataProvider('unsafeOutputs')]
    public function rejectsOutputEscapingProjectBeforeManagedWrite(string $target): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects(self::atLeastOnce())->method('read')->willReturn('central');
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument());
        $history->expects(self::once())->method('notes')->willReturn('notes');
        $paths->expects('' === $target || str_contains($target, "\0") ? self::never() : self::once())->method('isAbsolute')->willReturn(str_starts_with($target, '/'));
        $files->expects(self::never())->method('write');
        self::assertSame(2, $command($this->settings(), $this->createStub(OutputInterface::class), '1.0.0', $target));
    }

    public static function unsafeOutputs(): array
    {
        return [[''],['/outside.md'],['../outside.md'],['..\\outside.md'],['./notes.md'],['notes//release.md'],['notes/./release.md'],["notes\0.md"]];
    }

    #[Test]
    public function missingCentralDocumentIsAnInvalidRequest(): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects(self::atLeastOnce())->method('read')->willReturn(null);
        $history->expects(self::never())->method('parse');
        self::assertSame(2, $command($this->settings(), $this->createStub(OutputInterface::class), '1.0.0'));
    }

    #[Test]
    public function unsafeReadFailsOnErrorChannel(): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $history->expects(self::never())->method('parse');
        $files->expects(self::atLeastOnce())->method('read')->willThrowException(new RuntimeException('unsafe path'));
        $output = $this->createMock(ConsoleOutputInterface::class);
        $error = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('getErrorOutput')->willReturn($error);
        $error->expects(self::once())->method('writeln')->with('<error>unsafe path</error>');
        self::assertSame(1, $command($this->settings(), $output, '1.0.0'));
    }

    #[Test]
    #[DataProvider('outputFailures')]
    public function outputFailurePreservesLockOwnership(bool $acquired): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose(...$this->outputLocks($acquired));
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects($acquired ? self::exactly(2) : self::once())->method('read')->willReturnMap([['/consumer/CHANGELOG.md','central'],['/consumer/notes.md',null]]);
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument());
        $history->expects(self::once())->method('notes')->willReturn('exact');
        $paths->expects(self::once())->method('isAbsolute')->willReturn(false);
        if ($acquired) {
            $files->expects(self::once())->method('write')->willThrowException(new RuntimeException('write failed'));
        } else {
            $files->expects(self::never())->method('write');
        }
        self::assertSame(1, $command($this->settings(), $this->createStub(OutputInterface::class), '1.0.0', 'notes.md'));
    }

    public static function outputFailures(): array
    {
        return [[false], [true]];
    }

    #[Test]
    public function defaultVersionIgnoresTagsOutsideTheCurrentHistory(): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $files->expects(self::once())->method('read')->willReturn('central');
        $git->expects(self::once())->method('isRepository')->willReturn(true);
        $head = str_repeat('b', 40);
        $reachable = ['name' => 'v1.0.0','sha' => str_repeat('a', 40),'date' => null,'date_source' => null];
        $unrelated = ['name' => 'v9.0.0','sha' => str_repeat('c', 40),'date' => null,'date_source' => null];
        $git->expects(self::once())->method('resolveRef')->with('/consumer', 'HEAD')->willReturn($head);
        $git->expects(self::once())->method('tags')->willReturn([$unrelated,$reachable]);
        $git->expects(self::exactly(2))->method('isAncestor')->willReturnMap([['/consumer',$unrelated['sha'],$head,false],['/consumer',$reachable['sha'],$head,true]]);
        $importer->expects(self::once())->method('currentVersion')->with([$reachable], 'v')->willReturn('1.0.0');
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument());
        $history->expects(self::once())->method('notes')->with(self::anything(), '1.0.0')->willReturn('exact');
        self::assertSame(0, $command($this->settings(), $this->createStub(OutputInterface::class)));
    }

    #[Test]
    #[DataProvider('managedOutputs')]
    public function refusesManagedOutputAndCaseAliasesWithoutTakingALock(string $target): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose();
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects(self::once())->method('read')->with('/consumer/CHANGELOG.md')->willReturn('central');
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument());
        $history->expects(self::once())->method('notes')->willReturn('exact');
        $paths->expects(self::once())->method('isAbsolute')->willReturn(false);
        $files->expects(self::never())->method('write');
        self::assertSame(2, $command($this->settings(), $this->createStub(OutputInterface::class), '1.0.0', $target));
    }

    public static function managedOutputs(): array
    {
        return [['CHANGELOG.md'],['changelog.MD'],['.changelog'],['.CHANGELOG'],['.changelog/release-plan.json'],['.CHANGELOG/future.md'],['.changelog\\fragment.md']];
    }

    #[Test]
    #[DataProvider('existingExports')]
    public function refusesExistingExportsAndAlwaysReleasesItsLock(string $contents): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose(...$this->outputLocks(true));
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects(self::exactly(2))->method('read')->willReturnMap([['/consumer/CHANGELOG.md','central'],['/consumer/notes.md',$contents]]);
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument());
        $history->expects(self::once())->method('notes')->willReturn('exact');
        $paths->expects(self::once())->method('isAbsolute')->willReturn(false);
        $files->expects(self::never())->method('write');
        self::assertSame(2, $command($this->settings(), $this->createStub(OutputInterface::class), '1.0.0', 'notes.md'));
    }

    public static function existingExports(): array
    {
        return [[''],['previous export']];
    }

    #[Test]
    public function outputPreflightFailureReleasesItsLock(): void
    {
        [$command,$files,$history,$templates,$git,$importer,$paths] = $this->compose(...$this->outputLocks(true));
        $git->expects(self::never())->method('isRepository');
        $importer->expects(self::never())->method('currentVersion');
        $files->expects(self::exactly(2))->method('read')->willReturnCallback(static function (string $path): string {
            if ('/consumer/CHANGELOG.md' === $path) {
                return 'central';
            }
            throw new RuntimeException('unsafe export');
        });
        $history->expects(self::once())->method('parse')->willReturn(new HistoryDocument());
        $history->expects(self::once())->method('notes')->willReturn('exact');
        $paths->expects(self::once())->method('isAbsolute')->willReturn(false);
        $files->expects(self::never())->method('write');
        self::assertSame(1, $command($this->settings(), $this->createStub(OutputInterface::class), '1.0.0', 'notes.md'));
    }

    private function outputLocks(bool $acquired): array
    {
        $fragments = $this->createMock(ChangesetStoreInterface::class);
        $fragments->expects(self::once())->method('lockResource')->with('/consumer/.changelog')->willReturn('fixture-lock');
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn($acquired);
        $lock->expects($acquired ? self::once() : self::never())->method('release');
        $locks = $this->createMock(LockFactory::class);
        $locks->expects(self::once())->method('createLock')->with('fixture-lock')->willReturn($lock);
        return [$fragments, $locks];
    }

    private function compose(?ChangesetStoreInterface $fragments = null, ?LockFactory $locks = null): array
    {
        $options = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $options->expects(self::once())->method('create')->willReturn($this->options());
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $paths = $this->createMock(PackagePathResolverInterface::class);
        $paths->expects(self::atLeastOnce())->method('absolutePath')->willReturnCallback(static fn(string $path, ?string $cwd = null): string => '/consumer/' . $path);
        $history = $this->createMock(HistoryCodecInterface::class);
        $templates = $this->createStub(TemplateResolverInterface::class);
        $templates->method('resolve')->willReturn($this->createStub(TemplateInterface::class));
        $git = $this->createMock(GitRepositoryInterface::class);
        $importer = $this->createMock(HistoryImporterInterface::class);
        if (null === $fragments) {
            $fragments = $this->createMock(ChangesetStoreInterface::class);
            $fragments->expects(self::never())->method('lockResource');
        }
        if (null === $locks) {
            $locks = $this->createMock(LockFactory::class);
            $locks->expects(self::never())->method('createLock');
        }
        return [new NotesCommand($options, $paths, $files, $history, $templates, $git, $importer, $fragments, $locks),$files,$history,$templates,$git,$importer,$paths];
    }
}
