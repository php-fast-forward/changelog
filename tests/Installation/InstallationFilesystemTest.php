<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Installation;

use ArrayIterator;
use Closure;
use FastForward\Changelog\Filesystem\Factory\FinderFactoryInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Installation\InstallationFilesystem;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

#[CoversClass(InstallationFilesystem::class)]
#[UsesClass(PackagePathResolver::class)]
final class InstallationFilesystemTest extends TestCase
{
    private const string DIRECTORY = '/project/.github/workflows';
    private const string TARGET = self::DIRECTORY . '/changelog.yml';
    private const string STAGE = self::DIRECTORY . '/.fast-forward-install-test';
    private array $bytes = [];
    private array $directories = ['/project' => true];
    private array $links = [];
    private array $extraEntries = [];
    private array $effects = [];
    private bool $repository = true;
    private string $root = '/project';
    private string $originHead = "origin/main\n";
    private bool $successfulRef = true;
    private ?string $missingRead = null;
    private ?Closure $onScan = null;
    private ?Closure $onWrite = null;
    private ?Closure $onLink = null;
    private ?Closure $onStage = null;
    private int $scans = 0;

    /** Discovery reads only local Git evidence and leaves a missing workflow directory absent. */
    public function testInspectMissingDirectoryAndOriginDefault(): void
    {
        $this->originHead = "origin/maintenance/1.x\n";
        $result = $this->compose()->inspect('/project');

        self::assertSame(
            ['root' => '/project', 'base_branch' => 'maintenance/1.x', 'files' => [], 'diagnostics' => []],
            $result,
        );
        self::assertSame([], $this->effects);
    }

    /** A missing or malformed local origin default is a visible main suggestion, without a remote query. */
    #[DataProvider('unknownDefaults')]
    public function testDefaultFallbackIsExplicit(bool $success, string $output): void
    {
        $this->successfulRef = $success;
        $this->originHead = $output;
        $result = $this->compose()->inspect('/project');

        self::assertSame('main', $result['base_branch']);
        self::assertStringContainsString('origin/HEAD is unavailable', $result['diagnostics'][0]);
        self::assertSame([], $this->effects);
    }

    /** Supplies failed, malformed and empty symbolic-ref output. */
    public static function unknownDefaults(): iterable
    {
        yield [false, 'origin/main'];
        yield [true, 'main'];
        yield [true, 'origin/'];
    }

    /** Non-Git directories cannot be installed into merely because they exist. */
    public function testNonRepositoryIsRejected(): void
    {
        $this->repository = false;
        $this->expectExceptionMessage('existing Git repository');
        $this->compose()->inspect('/project');
    }

    /** Inventory preserves exact YAML bytes and ignores immediate non-YAML files and directories. */
    public function testInventoryIsSortedAndNonRecursive(): void
    {
        $this->directories[self::DIRECTORY] = true;
        $this->bytes[self::DIRECTORY . '/z.yaml'] = "z: exact\n\n";
        $this->bytes[self::DIRECTORY . '/a.YML'] = "a: exact\n";
        $this->bytes[self::DIRECTORY . '/README.md'] = 'ignored';
        $this->extraEntries = [['nested', false, false]];
        $result = $this->compose()->inspect('/project');

        self::assertSame(
            ['.github/workflows/a.YML' => "a: exact\n", '.github/workflows/z.yaml' => "z: exact\n\n"],
            $result['files'],
        );
        self::assertSame([], $this->effects);
    }

    /** A symlink encountered in the workflow inventory is never followed, including non-YAML names. */
    public function testSymbolicInventoryEntryIsRejected(): void
    {
        $this->directories[self::DIRECTORY] = true;
        $this->extraEntries = [['link.md', true, false]];
        $this->expectExceptionMessage('does not follow symbolic links');
        $this->compose()->inspect('/project');
    }

    /** A disappearing workflow cannot be silently omitted from conflict detection. */
    public function testDisappearingWorkflowIsRejected(): void
    {
        $this->directories[self::DIRECTORY] = true;
        $this->bytes[self::TARGET] = 'before';
        $this->missingRead = self::TARGET;
        $this->expectExceptionMessage('disappeared during inspection');
        $this->compose()->inspect('/project');
    }

    /** Metadata reads preserve exact bytes and use correctly bounded Windows/UNC ancestry. */
    public function testMetadataReadsAreGuardedAndPortable(): void
    {
        $adapter = $this->compose();
        foreach (['/project', 'C:/project', '//server/share/project'] as $root) {
            $this->bytes[$root . '/skills-lock.json'] = 'exact';
            self::assertSame('exact', $adapter->read($root, 'skills-lock.json'));
        }
        self::assertNull($adapter->read('/project', 'missing.json'));
        self::assertSame([], $this->effects);
    }

    /** Paths supplied to the I/O boundary cannot escape or alias the declared consumer root. */
    #[DataProvider('unsafeRelativePaths')]
    public function testUnsafeRelativePathsAreRejected(string $path): void
    {
        $this->expectExceptionMessage('Installation paths must be relative');
        $this->compose()->read('/project', $path);
    }

    /** Supplies absolute, traversal, control and alternate-separator spellings. */
    public static function unsafeRelativePaths(): iterable
    {
        foreach (['', '/outside', '../outside', 'a//b', 'a/./b', 'a\\b', "a\0b", "a\nb"] as $path) {
            yield [$path];
        }
    }

    /** Repository roots returned by a failing Git boundary must still satisfy absolute safe path syntax. */
    #[DataProvider('unsafeRoots')]
    public function testUnsafeGitRootsAreRejected(string $root): void
    {
        $this->root = $root;
        $this->expectExceptionMessage('absolute path without traversal');
        $this->compose()->inspect('/project');
    }

    /** Provides untrusted root output independent from the normalized consumer input. */
    public static function unsafeRoots(): iterable
    {
        yield ['relative'];
        yield ['/project/../outside'];
        yield ["/project\0bad"];
        yield ['/project/./nested'];
    }

    /** Symbolic targets, parents and drive/share roots all stop before reads or writes. */
    #[DataProvider('symbolicAncestors')]
    public function testSymbolicAncestryIsRejected(string $root, string $link): void
    {
        $this->links[$link] = '/outside';
        $this->expectExceptionMessage('ancestors must not be symbolic links');
        $this->compose()->read($root, 'skills-lock.json');
    }

    /** Covers project, metadata, drive and share ancestry without touching those native paths. */
    public static function symbolicAncestors(): iterable
    {
        yield ['/project', '/project'];
        yield ['/project', '/project/skills-lock.json'];
        yield ['C:/project', 'C:/'];
        yield ['//server/share/project', '//server/share'];
    }

    /** A fresh workflow is published as complete bytes through the exclusive link boundary, then the stage is removed. */
    public function testExclusiveCreationPublishesCompleteBytes(): void
    {
        $this->compose()->create('/project', '.github/workflows/changelog.yml', "complete\n", []);

        self::assertSame("complete\n", $this->bytes[self::TARGET]);
        self::assertArrayNotHasKey(self::STAGE, $this->bytes);
        self::assertSame(['mkdir', 'stage', 'write', 'link', 'remove'], $this->effects);
    }

    /** An existing target can never be overwritten even when no workflows were present in the accepted snapshot. */
    public function testExistingTargetIsPreserved(): void
    {
        $this->bytes[self::TARGET] = 'human';
        try {
            $this->compose()->create('/project', '.github/workflows/changelog.yml', 'new', []);
            self::fail('Creation must refuse existing bytes.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('changed after inspection', $failure->getMessage());
        }
        self::assertSame('human', $this->bytes[self::TARGET]);
        self::assertSame([], $this->effects);
    }

    /** Drift in another workflow blocks before directory creation, even when the selected target is absent. */
    public function testInventoryDriftBeforeStagingBlocksWithoutWrites(): void
    {
        $this->directories[self::DIRECTORY] = true;
        $this->bytes[self::DIRECTORY . '/release.yml'] = 'human';
        $this->expectExceptionMessage('changed after inspection');
        $this->compose()->create('/project', '.github/workflows/changelog.yml', 'new', []);
    }

    /** A new release integration introduced after staging is preserved, and temporary installer bytes are removed. */
    public function testInventoryDriftDuringStagingBlocksAndCleansStage(): void
    {
        $this->onScan = function (): void {
            $this->bytes[self::DIRECTORY . '/release.yml'] = 'human';
        };
        try {
            $this->compose()->create('/project', '.github/workflows/changelog.yml', 'new', []);
            self::fail('Creation must refuse changed workflows.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('changed before creation', $failure->getMessage());
        }
        self::assertSame('human', $this->bytes[self::DIRECTORY . '/release.yml']);
        self::assertArrayNotHasKey(self::TARGET, $this->bytes);
        self::assertArrayNotHasKey(self::STAGE, $this->bytes);
    }

    /** A concurrent creator wins the atomic no-replacement operation; its exact bytes remain intact. */
    public function testConcurrentTargetCreationCannotBeClobbered(): void
    {
        $this->onLink = function (): void {
            $this->bytes[self::TARGET] = 'human';
        };
        try {
            $this->compose()->create('/project', '.github/workflows/changelog.yml', 'new', []);
            self::fail('Creation must refuse a concurrent target.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('created exclusively', $failure->getMessage());
        }
        self::assertSame('human', $this->bytes[self::TARGET]);
        self::assertArrayNotHasKey(self::STAGE, $this->bytes);
    }

    /** A symbolic target introduced after staging prevents the link operation and leaves foreign state alone. */
    public function testTargetSymlinkRaceIsRejected(): void
    {
        $this->onWrite = function (): void {
            $this->links[self::TARGET] = '/outside';
        };
        $this->expectExceptionMessage('must not be symbolic links');
        $this->compose()->create('/project', '.github/workflows/changelog.yml', 'new', []);
    }

    /** A compromised staging name is never written through, and cleanup does not promote it. */
    public function testStagingSymlinkIsRejected(): void
    {
        $this->onStage = function (): void {
            $this->links[self::STAGE] = '/outside';
        };
        $this->expectExceptionMessage('must not be symbolic links');
        $this->compose()->create('/project', '.github/workflows/changelog.yml', 'new', []);
    }

    /** Post-publication byte changes are reported without deleting or rewriting the now externally edited caller. */
    public function testVerificationFailurePreservesChangedTarget(): void
    {
        $this->onLink = function (): void {
            $this->bytes[self::STAGE] = 'changed';
        };
        try {
            $this->compose()->create('/project', '.github/workflows/changelog.yml', 'new', []);
            self::fail('Verification must detect changed bytes.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('changed during verification', $failure->getMessage());
        }
        self::assertSame('changed', $this->bytes[self::TARGET]);
        self::assertArrayNotHasKey(self::STAGE, $this->bytes);
    }

    /** Composes all I/O through in-memory doubles; no apparent fixture path is observed natively. */
    private function compose(): InstallationFilesystem
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('readlink')->willReturnCallback(fn(string $path): ?string => $this->links[$path] ?? null);
        $filesystem->method('exists')->willReturnCallback(
            fn(string $path): bool => isset($this->directories[$path], $this->bytes[$path])
            || isset($this->directories[$path]) || isset($this->bytes[$path]),
        );
        $filesystem->method('mkdir')->willReturnCallback(function (string $path): void {
            $this->effects[] = 'mkdir';
            $this->directories[$path] = true;
        });
        $filesystem->method('tempnam')->willReturnCallback(function (): string {
            $this->effects[] = 'stage';
            $this->bytes[self::STAGE] = '';
            ($this->onStage ?? static fn() => null)();

            return self::STAGE;
        });
        $filesystem->method('dumpFile')->willReturnCallback(function (string $path, string $contents): void {
            $this->effects[] = 'write';
            $this->bytes[$path] = $contents;
            ($this->onWrite ?? static fn() => null)();
        });
        $filesystem->method('remove')->willReturnCallback(function (string $path): void {
            $this->effects[] = 'remove';
            unset($this->bytes[$path]);
        });
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('read')->willReturnCallback(
            fn(string $path): ?string => $this->missingRead === $path ? null : ($this->bytes[$path] ?? null),
        );
        $finders = $this->createStub(FinderFactoryInterface::class);
        $finders->method('create')->willReturnCallback(function (): Finder {
            $finder = $this->createStub(Finder::class);
            foreach (['in', 'depth', 'ignoreDotFiles', 'sortByName'] as $method) {
                $finder->method($method)->willReturnSelf();
            }
            $finder->method('getIterator')->willReturnCallback(function (): ArrayIterator {
                ++$this->scans;
                ($this->onScan ?? static fn() => null)();
                $entries = $this->extraEntries;
                foreach (array_keys($this->bytes) as $path) {
                    if (dirname($path) === self::DIRECTORY) {
                        $entries[] = [basename($path), false, true];
                    }
                }

                return new ArrayIterator(array_map(function (array $data): SplFileInfo {
                    $entry = $this->createStub(SplFileInfo::class);
                    $entry->method('getFilename')->willReturn($data[0]);
                    $entry->method('isLink')->willReturn($data[1]);
                    $entry->method('isFile')->willReturn($data[2]);

                    return $entry;
                }, $entries));
            });

            return $finder;
        });
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('isRepository')->willReturnCallback(fn(): bool => $this->repository);
        $git->method('repositoryRoot')->willReturnCallback(fn(): string => $this->root);
        $processes = $this->createStub(ProcessFactoryInterface::class);
        $processes->method('create')->willReturnCallback(function (array $command): Process {
            self::assertSame([
                'git', '-C', $this->root, 'symbolic-ref', '--quiet', '--short', 'refs/remotes/origin/HEAD'],
                $command,
            );
            $process = $this->createStub(Process::class);
            $process->method('getOutput')->willReturn($this->originHead);
            $process->method('isSuccessful')->willReturn($this->successfulRef);

            return $process;
        });

        return new InstallationFilesystem($filesystem, $files, new PackagePathResolver(
            '/project',
        ), $finders, $git, $processes,
            function (string $source, string $target): bool {
                $this->effects[] = 'link';
                ($this->onLink ?? static fn() => null)();
                if (isset($this->bytes[$target])) {
                    return false;
                }
                $this->bytes[$target] = $this->bytes[$source];

                return true;
            });
    }
}
