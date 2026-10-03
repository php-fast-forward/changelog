<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset\Store;

use ArrayIterator;
use FastForward\Changelog\Changeset\Store\FilesystemChangesetStore;
use FastForward\Changelog\Changeset\Store\WriteResult;
use FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface;
use FastForward\Changelog\Filesystem\Factory\PathExceptionFactoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

#[CoversClass(FilesystemChangesetStore::class)]
final class FilesystemChangesetStoreTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function equivalentAbsoluteDirectoriesShareALockWhileDifferentProjectsDoNot(): void
    {
        $store = $this->store($this->filesystem());
        self::assertSame($store->lockResource('/repo/.changelog'), $store->lockResource('/repo//./.changelog/'));
        self::assertNotSame($store->lockResource('/repo/.changelog'), $store->lockResource('/other/.changelog'));
        self::assertSame($store->lockResource('C:/repo/.changelog'), $store->lockResource('c:\repo\.changelog'));
        self::assertSame($store->lockResource('//server/share/.changelog'), $store->lockResource('\\\\server\\share\\.changelog'));
    }

    #[Test]
    public function relativeLockResourcesFailThroughAnInjectedExceptionFactory(): void
    {
        $exceptions = $this->prophesize(PathExceptionFactoryInterface::class);
        $exceptions->create('.changelog')->willReturn(new InvalidArgumentException('absolute required'))->shouldBeCalledOnce();
        $store = $this->store($this->filesystem(), exceptions: $exceptions);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute required');
        $store->lockResource('.changelog');
    }

    #[Test]
    #[TestWith(['//incomplete'])]
    #[TestWith(['//../share/entry.md'])]
    #[TestWith(['//server/../entry.md'])]
    #[TestWith(['relative.md'])]
    #[TestWith(['/repo/../elsewhere/entry.md'])]
    #[TestWith(['/repo/.changelog/.hidden.md'])]
    #[TestWith(['/repo/.changelog/AGENTS.md'])]
    #[TestWith(["/repo/.changelog/nul\0name.md"])]
    public function malformedWritePathsHaveNoFilesystemEffects(string $path): void
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $store = $this->store($filesystem);
        self::assertSame(WriteResult::UnsafePath, $store->inspectWrite($path));
        self::assertSame(WriteResult::UnsafePath, $store->write($path, 'body'));
    }

    #[Test]
    public function inspectWriteDistinguishesExistingAndAvailableIdentifiers(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/repo/.changelog/entry.md')->willReturn(true, false)->shouldBeCalledTimes(2);
        $store = $this->store($filesystem);
        self::assertSame(WriteResult::Existing, $store->inspectWrite('/repo/.changelog/entry.md'));
        self::assertSame(WriteResult::Available, $store->inspectWrite('/repo/.changelog/entry.md'));
    }

    #[Test]
    public function ancestrySymlinksAreRejectedBeforeAccessOutsideTheProject(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readlink('/repo')->willReturn('/elsewhere')->shouldBeCalledTimes(4);
        $filesystem->readFile(Argument::any())->shouldNotBeCalled();
        $filesystem->exists(Argument::any())->shouldNotBeCalled();
        $store = $this->store($filesystem, locks: $this->locks());
        self::assertNull($store->read('/repo/.changelog/entry.md'));
        self::assertNull($store->paths('/repo/.changelog'));
        self::assertSame(WriteResult::UnsafePath, $store->inspectWrite('/repo/.changelog/entry.md'));
        self::assertSame(WriteResult::UnsafePath, $store->write('/repo/.changelog/entry.md', 'body'));
    }

    #[Test]
    public function pathsReturnsEmptyForAbsentDirectoryWithoutCreatingIt(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/repo/.changelog')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->mkdir(Argument::any())->shouldNotBeCalled();
        self::assertSame([], $this->store($filesystem)->paths('/repo/.changelog'));
        self::assertNull($this->store($filesystem)->paths('relative'));
    }

    #[Test]
    public function discoveryRetainsNestedHiddenAndNestedReservedNamesForValidation(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/repo/.changelog')->willReturn(true)->shouldBeCalledOnce();
        $finder = $this->prophesize(Finder::class);
        $finderObject = $finder->reveal();
        $regular = $this->prophesize(SplFileInfo::class);
        $regular->isFile()->willReturn(true)->shouldBeCalledOnce();
        $regular->isLink()->shouldNotBeCalled();
        $brokenLink = $this->prophesize(SplFileInfo::class);
        $brokenLink->isFile()->willReturn(false)->shouldBeCalledOnce();
        $brokenLink->isLink()->willReturn(true)->shouldBeCalledOnce();
        $directory = $this->prophesize(SplFileInfo::class);
        $directory->isFile()->willReturn(false)->shouldBeCalledOnce();
        $directory->isLink()->willReturn(false)->shouldBeCalledOnce();
        $finder->filter(Argument::type('callable'))->will(function (array $arguments) use ($regular, $brokenLink, $directory, $finderObject): Finder {
            self::assertTrue($arguments[0]($regular->reveal()));
            self::assertTrue($arguments[0]($brokenLink->reveal()));
            self::assertFalse($arguments[0]($directory->reveal()));
            return $finderObject;
        })->shouldBeCalledOnce();
        $finder->ignoreDotFiles(false)->willReturn($finderObject)->shouldBeCalledOnce();
        $finder->ignoreVCS(false)->willReturn($finderObject)->shouldBeCalledOnce();
        $finder->name('*.md')->willReturn($finderObject)->shouldBeCalledOnce();
        $finder->sortByName()->willReturn($finderObject)->shouldBeCalledOnce();
        $finder->in('/repo/.changelog')->willReturn($finderObject)->shouldBeCalledOnce();
        $entries = [];

        foreach (['z.md', 'AGENTS.md', 'nested/AGENTS.md', '.hidden.md', 'broken-link.md'] as $name) {
            $file = $this->prophesize(SplFileInfo::class);
            $file->getRelativePathname()->willReturn($name)->shouldBeCalledOnce();
            if ('AGENTS.md' !== $name) {
                $file->getPathname()->willReturn('/repo/.changelog/' . $name)->shouldBeCalledOnce();
            }
            $entries[] = $file->reveal();
        }

        $finder->getIterator()->willReturn(new ArrayIterator([...$entries, new stdClass()]))->shouldBeCalledOnce();
        $factory = $this->prophesize(FinderFactoryInterface::class);
        $factory->create()->willReturn($finderObject)->shouldBeCalledOnce();

        self::assertSame(['/repo/.changelog/.hidden.md', '/repo/.changelog/broken-link.md', '/repo/.changelog/nested/AGENTS.md', '/repo/.changelog/z.md'], $this->store($filesystem, finder: $factory)->paths('/repo/.changelog'));
    }

    #[Test]
    public function readPreservesOriginalContentsAndSupportsAnAbsoluteDrivePath(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readFile('C:/repo/entry.md')->willReturn("exact  \n")->shouldBeCalledOnce();
        $store = $this->store($filesystem);
        self::assertSame("exact  \n", $store->read('c:\repo\entry.md'));
        self::assertNull($store->read('relative.md'));
        $filesystem->readFile('//server/share/entry.md')->willReturn('network share body')->shouldBeCalledOnce();
        self::assertSame('network share body', $store->read('//server/share/entry.md'));
    }

    #[Test]
    public function readFailurePropagatesWithoutMaskingTheIntegrationError(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readFile('/repo/.changelog/entry.md')->willThrow(new RuntimeException('read failed'))->shouldBeCalledOnce();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('read failed');
        $this->store($filesystem)->read('/repo/.changelog/entry.md');
    }

    #[Test]
    public function writeCreatesOnlyTheMissingDirectoryAndOneFragment(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/repo/.changelog/entry.md')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->exists('/repo/.changelog')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->mkdir('/repo/.changelog')->shouldBeCalledOnce();
        $filesystem->dumpFile('/repo/.changelog/entry.md', 'body')->shouldBeCalledOnce();
        self::assertSame(WriteResult::Created, $this->store($filesystem, locks: $this->locks())->write('/repo/.changelog/entry.md', 'body'));
    }

    #[Test]
    public function aSecondWriterCannotOverwriteTheFirstFragment(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/repo/.changelog/entry.md')->willReturn(false, true)->shouldBeCalledTimes(2);
        $filesystem->exists('/repo/.changelog')->willReturn(true)->shouldBeCalledOnce();
        $filesystem->mkdir(Argument::any())->shouldNotBeCalled();
        $filesystem->dumpFile('/repo/.changelog/entry.md', 'first')->shouldBeCalledOnce();
        $filesystem->dumpFile('/repo/.changelog/entry.md', 'second')->shouldNotBeCalled();
        $store = $this->store($filesystem, locks: $this->locks(times: 2));
        self::assertSame(WriteResult::Created, $store->write('/repo/.changelog/entry.md', 'first'));
        self::assertSame(WriteResult::Existing, $store->write('/repo/.changelog/entry.md', 'second'));
    }

    #[Test]
    public function unavailableLockProducesNoFilesystemReadsOrWrites(): void
    {
        self::assertSame(WriteResult::LockUnavailable, $this->store($this->prophesize(Filesystem::class), locks: $this->locks(acquired: false))->write('/repo/.changelog/entry.md', 'body'));
    }

    #[Test]
    public function symlinkIntroducedDuringParentCreationIsRejectedBeforeDumping(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readlink('/repo/.changelog/entry.md')->willReturn(null, '/outside')->shouldBeCalledTimes(2);
        $filesystem->exists('/repo/.changelog/entry.md')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->exists('/repo/.changelog')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->mkdir('/repo/.changelog')->shouldBeCalledOnce();
        $filesystem->dumpFile(Argument::any(), Argument::any())->shouldNotBeCalled();
        self::assertSame(WriteResult::UnsafePath, $this->store($filesystem, locks: $this->locks())->write('/repo/.changelog/entry.md', 'body'));
    }

    #[Test]
    public function failedWriteReleasesItsLockAndLeavesTheOriginalFailureVisible(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/repo/.changelog/entry.md')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->exists('/repo/.changelog')->willReturn(true)->shouldBeCalledOnce();
        $filesystem->dumpFile('/repo/.changelog/entry.md', 'body')->willThrow(new RuntimeException('disk unavailable'))->shouldBeCalledOnce();
        $store = $this->store($filesystem, locks: $this->locks());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disk unavailable');
        $store->write('/repo/.changelog/entry.md', 'body');
    }

    #[Test]
    public function removalIsExplicitDeduplicatedAndIdempotentWithEmptyInputANoOp(): void
    {
        $filesystem = $this->filesystem();
        $paths = ['/repo/.changelog/one.md', '/repo/.changelog/two.md'];
        $filesystem->remove($paths)->shouldBeCalledTimes(2);
        $store = $this->store($filesystem);
        $store->remove([]);
        $store->remove([...$paths, $paths[0]]);
        $store->remove($paths);
    }

    #[Test]
    #[TestWith(['/repo/.changelog/AGENTS.md'])]
    #[TestWith(['relative.md'])]
    #[TestWith(['/repo/.changelog/../entry.md'])]
    public function unsafeRemovalSelectionFailsBeforeRemovingAnyValidSibling(string $invalid): void
    {
        $filesystem = $this->filesystem();
        $filesystem->remove(Argument::any())->shouldNotBeCalled();
        $exceptions = $this->prophesize(PathExceptionFactoryInterface::class);
        $exceptions->create($invalid)->willReturn(new InvalidArgumentException('unsafe'))->shouldBeCalledOnce();
        $store = $this->store($filesystem, exceptions: $exceptions);
        $this->expectException(InvalidArgumentException::class);
        $store->remove(['/repo/.changelog/valid.md', $invalid]);
    }

    #[Test]
    public function symbolicRemovalSelectionFailsBeforeAnyDeletion(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readlink('/repo/.changelog/link.md')->willReturn('/outside')->shouldBeCalledOnce();
        $filesystem->remove(Argument::any())->shouldNotBeCalled();
        $exceptions = $this->prophesize(PathExceptionFactoryInterface::class);
        $exceptions->create('/repo/.changelog/link.md')->willReturn(new InvalidArgumentException('unsafe'))->shouldBeCalledOnce();
        $this->expectException(InvalidArgumentException::class);
        $this->store($filesystem, exceptions: $exceptions)->remove(['/repo/.changelog/link.md']);
    }

    /** Creates a filesystem double; no real path is inspected. */
    private function filesystem(): ObjectProphecy
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $filesystem->readlink(Argument::type('string'))->willReturn(null);
        return $filesystem;
    }

    /** Requires the exact shared resource and verifies release of each granted lock. */
    private function locks(bool $acquired = true, int $times = 1): ObjectProphecy
    {
        $factory = $this->prophesize(LockFactory::class);
        $lock = $this->prophesize(SharedLockInterface::class);
        $factory->createLock('fast-forward/changelog:' . hash('sha256', '/repo/.changelog'))->willReturn($lock->reveal())->shouldBeCalledTimes($times);
        $lock->acquire(true)->willReturn($acquired)->shouldBeCalledTimes($times);
        if ($acquired) {
            $lock->release()->shouldBeCalledTimes($times);
        } else {
            $lock->release()->shouldNotBeCalled();
        }
        return $factory;
    }

    /** Builds the real store with only mocked collaborator boundaries. */
    private function store(ObjectProphecy $filesystem, ?ObjectProphecy $finder = null, ?ObjectProphecy $locks = null, ?ObjectProphecy $exceptions = null): FilesystemChangesetStore
    {
        return new FilesystemChangesetStore($filesystem->reveal(), ($finder ?? $this->prophesize(FinderFactoryInterface::class))->reveal(), ($locks ?? $this->prophesize(LockFactory::class))->reveal(), ($exceptions ?? $this->prophesize(PathExceptionFactoryInterface::class))->reveal());
    }
}
