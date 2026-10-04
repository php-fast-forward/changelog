<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Filesystem;

use FastForward\Changelog\Filesystem\ManagedFileStore;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Tests\Filesystem\Fixture\DirectoryName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

// Register lexical doubles before either adapter can bind to the native function.
require_once __DIR__ . '/Fixture/dirname.php';

#[CoversClass(ManagedFileStore::class)]
final class ManagedFileStoreTest extends TestCase
{
    use ProphecyTrait;

    /** Construction and absent-file reads MUST neither create parents nor read a nonexistent target. */
    public function testAbsentFileReturnsNullWithoutCreatingAnything(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/consumer/CHANGELOG.md')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->readFile(Argument::any())->shouldNotBeCalled();
        $filesystem->mkdir(Argument::any())->shouldNotBeCalled();
        self::assertNull($this->store($filesystem)->read('/consumer/CHANGELOG.md'));
    }

    /** Original spaces/newlines MUST survive reading and Windows/UNC paths must stay absolute. */
    public function testReadsExactBytesIncludingDriveAndSharePaths(): void
    {
        $filesystem = $this->filesystem();
        foreach (['/consumer/CHANGELOG.md', 'C:/consumer/CHANGELOG.md', '//server/share/CHANGELOG.md'] as $path) {
            $filesystem->exists($path)->willReturn(true)->shouldBeCalledOnce();
            $filesystem->readFile($path)->willReturn("Exact  bytes\n")->shouldBeCalledOnce();
        }
        $store = $this->store($filesystem);
        self::assertSame("Exact  bytes\n", $store->read('/consumer/CHANGELOG.md'));
        self::assertSame("Exact  bytes\n", $store->read('c:\\consumer\\CHANGELOG.md'));
        self::assertSame("Exact  bytes\n", $store->read('//server/share/CHANGELOG.md'));
        $filesystem->readlink('C:')->shouldNotBeCalled();
        $filesystem->readlink('//server')->shouldNotBeCalled();
    }

    /** Invalid lexical spellings MUST fail before any filesystem operation. */
    #[DataProvider('unsafePaths')]
    public function testUnsafePathsFailBeforeReadsOrWrites(string $path): void
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $store = $this->store($filesystem);
        foreach (['read', 'write'] as $operation) {
            try {
                'read' === $operation ? $store->read($path) : $store->write($path, 'contents');
                self::fail('Unsafe managed path must fail.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Unsafe managed path: ' . $path, $error->getMessage());
            }
        }
    }

    /** Supplies traversal, relative, malformed shares and NUL spellings. */
    public static function unsafePaths(): iterable
    {
        foreach (['relative.md', '/consumer/../outside.md', '/consumer/./file.md', '//server', '//server/share', '//../share/file.md', '//server/../file.md', "nul\0path"] as $path) {
            yield [$path];
        }
    }

    /** Any symbolic target or ancestor MUST be rejected before reading or replacing bytes. */
    public function testSymbolicAncestorIsNeverFollowed(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readlink('/consumer')->willReturn('/elsewhere')->shouldBeCalledTimes(2);
        $filesystem->exists(Argument::any())->shouldNotBeCalled();
        $filesystem->readFile(Argument::any())->shouldNotBeCalled();
        $filesystem->dumpFile(Argument::any(), Argument::any())->shouldNotBeCalled();
        $store = $this->store($filesystem);
        foreach (['read', 'write'] as $operation) {
            try {
                'read' === $operation ? $store->read('/consumer/CHANGELOG.md') : $store->write('/consumer/CHANGELOG.md', 'contents');
                self::fail('Symbolic ancestor must fail.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Symbolic managed path or ancestor: /consumer', $error->getMessage());
            }
        }
    }

    /** A symbolic drive root must fail before managed-file I/O under either native parent spelling. */
    #[TestWith(['C:'])]
    #[TestWith(['C:/'])]
    public function testSymbolicDriveRootIsNeverFollowed(string $parent): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readlink('C:/')->willReturn('/outside')->shouldBeCalledTimes(2);
        $filesystem->readlink('C:')->shouldNotBeCalled();
        $filesystem->exists(Argument::any())->shouldNotBeCalled();
        $filesystem->readFile(Argument::any())->shouldNotBeCalled();
        $filesystem->dumpFile(Argument::any(), Argument::any())->shouldNotBeCalled();
        $store = $this->store($filesystem);

        DirectoryName::withParent('C:/consumer', $parent, static function () use ($store): void {
            foreach (['read', 'write'] as $operation) {
                try {
                    'read' === $operation ? $store->read('c:\\consumer\\CHANGELOG.md') : $store->write('c:\\consumer\\CHANGELOG.md', 'contents');
                    self::fail('A symbolic drive root must prevent managed-file access.');
                } catch (RuntimeException $error) {
                    self::assertSame('Symbolic managed path or ancestor: C:/', $error->getMessage());
                }
            }
        });

        self::assertSame(\dirname('C:/consumer'), DirectoryName::resolve('C:/consumer'));
    }

    /** Writes MUST create only a missing parent and recheck ancestry before replacement. */
    public function testWritesCreateMissingParentThenDumpExactContents(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/consumer/.changelog')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->mkdir('/consumer/.changelog')->shouldBeCalledOnce();
        $filesystem->dumpFile('/consumer/.changelog/release-plan.json', "Exact receipt\n")->shouldBeCalledOnce();
        $filesystem->readlink('/consumer/.changelog/release-plan.json')->willReturn(null)->shouldBeCalledTimes(2);
        $this->store($filesystem)->write('/consumer/.changelog/release-plan.json', "Exact receipt\n");
    }

    /** Existing parents MUST not be recreated and failed writes MUST remain visible to the transaction. */
    public function testExistingParentAndFilesystemFailureArePreserved(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->exists('/consumer')->willReturn(true)->shouldBeCalledOnce();
        $filesystem->mkdir(Argument::any())->shouldNotBeCalled();
        $filesystem->dumpFile('/consumer/CHANGELOG.md', 'contents')->willThrow(new RuntimeException('disk full'))->shouldBeCalledOnce();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disk full');
        $this->store($filesystem)->write('/consumer/CHANGELOG.md', 'contents');
    }

    /** A symlink introduced by parent creation MUST fail the second ancestry check. */
    public function testParentCreationRaceDoesNotReachDumpFile(): void
    {
        $filesystem = $this->filesystem();
        $filesystem->readlink('/consumer/.changelog/release-plan.json')->willReturn(null, '/outside')->shouldBeCalledTimes(2);
        $filesystem->exists('/consumer/.changelog')->willReturn(false)->shouldBeCalledOnce();
        $filesystem->mkdir('/consumer/.changelog')->shouldBeCalledOnce();
        $filesystem->dumpFile(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Symbolic managed path');
        $this->store($filesystem)->write('/consumer/.changelog/release-plan.json', 'receipt');
    }

    /** Supplies a filesystem boundary that never calls any native file function. */
    private function filesystem(): ObjectProphecy
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $filesystem->readlink(Argument::type('string'))->willReturn(null);
        return $filesystem;
    }

    /** Creates the real adapter with only mocked I/O and exception collaborators. */
    private function store(ObjectProphecy $filesystem): ManagedFileStore
    {
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('failure')->willReturnCallback(static fn(string $message, ?Throwable $previous = null): RuntimeException => new RuntimeException($message, previous: $previous));
        return new ManagedFileStore($filesystem->reveal(), $exceptions);
    }
}
