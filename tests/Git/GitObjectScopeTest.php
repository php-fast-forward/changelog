<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Git;

use FastForward\Changelog\Git\GitObjectScope;
use FastForward\Changelog\Git\GitRepository;
use FastForward\Changelog\Process\Factory\ProcessFactoryInterface;
use FastForward\Changelog\Release\ReleaseMetadataParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(GitObjectScope::class)]
#[UsesClass(GitRepository::class)]
#[UsesClass(ReleaseMetadataParser::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\FastForward\Changelog\Filesystem\PackagePathResolver::class)]
final class GitObjectScopeTest extends TestCase
{
    private const string ROOT = '/private-fixture/tmp';
    private const string PROJECT = '/consumer project';

    /** A private object view preserves structured argv and unrelated environment while removing all native tokens. */
    #[TestWith(['/consumer/.git/objects', false])]
    #[TestWith(['/consumer/objects:with "quotes"/ação', true])]
    #[TestWith(['C:\\consumer project\\objects', true])]
    public function testScopedCallbackGetsAnIsolatedGitAdapter(string $original, bool $inherited): void
    {
        $objects = $this->objects();
        $sha = str_repeat('a', 40);
        $discovery = $this->discovery($original . "\r\n");
        $scoped = $this->createMock(Process::class);
        $scoped->expects(self::once())->method('getEnv')->willReturn($inherited ? [
            'FIXTURE_PRESERVED' => 'value', 'GITHUB_TOKEN' => 'synthetic-private',
            'GH_TOKEN' => 'synthetic-private', 'FF_CHANGELOG_TOKEN' => 'synthetic-private',
            'FF_CHANGELOG_INPUTS' => 'synthetic-private', 'GIT_OBJECT_DIRECTORY' => '/untrusted',
            'GIT_NO_LAZY_FETCH' => '0', 'GIT_CONFIG_VALUE_0' => 'always',
        ] : []);
        $expected = [
            ...($inherited ? ['FIXTURE_PRESERVED' => 'value', 'GIT_CONFIG_VALUE_0' => 'always'] : []),
            'GIT_OBJECT_DIRECTORY' => $objects,
            'GIT_ALTERNATE_OBJECT_DIRECTORIES' => json_encode(
                $original,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            'GIT_NO_LAZY_FETCH' => '1', 'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ALLOW_PROTOCOL' => '',
            'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false, 'FF_CHANGELOG_INPUTS' => false,
        ];
        $scoped->expects(self::once())->method('setEnv')->with(self::callback(static function (array $environment) use (
            $expected
        ): bool {
            self::assertEquals($expected, $environment);
            self::assertStringNotContainsString('synthetic-private', json_encode($environment, JSON_THROW_ON_ERROR));

            return true;
        }))->willReturnSelf();
        $scoped->expects(self::once())->method('run')->willReturn(0);
        $scoped->expects(self::once())->method('isSuccessful')->willReturn(true);
        $scoped->expects(self::once())->method('getOutput')->willReturn($sha . "\n");
        $factory = $this->createMock(ProcessFactoryInterface::class);
        $calls = 0;
        $factory->expects(self::exactly(2))->method('create')->willReturnCallback(static function (array $command) use (
            &$calls,
            $discovery,
            $scoped
        ): Process {
            self::assertSame(
                0 === $calls ? [
                    'git',
                    '-C',
                    self::PROJECT,
                    'rev-parse',
                    '--path-format=absolute',
                    '--git-path',
                    'objects',
                ] : [
                    'git',
                    '-C',
                    self::PROJECT,
                    'rev-parse',
                    '--verify',
                    '--end-of-options',
                    'HEAD^{commit}',
                ],
                $command,
            );
            self::assertStringNotContainsString('synthetic-private', implode(' ', $command));

            return 0 === $calls++ ? $discovery : $scoped;
        });
        $filesystem = $this->filesystem();
        $result = (new GitObjectScope(
            $factory,
            $filesystem,
            $this->random(),
            self::ROOT . '/',
            new \FastForward\Changelog\Filesystem\PackagePathResolver(
                '/',
            ),
        ))->run(
            self::PROJECT,
            static fn(GitRepository $git): string => $git->resolveRef(self::PROJECT),
        );
        self::assertSame($sha, $result);
    }

    /** The result is returned unchanged, and callback failures still remove the private object directory. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testCleanupRunsAfterCallback(bool $failure): void
    {
        $factory = $this->discoveryFactory($this->discovery('/consumer/.git/objects'));
        $filesystem = $this->filesystem();
        $expected = new \stdClass();
        $error = new RuntimeException('Fixture callback failed.');
        if ($failure) {
            $this->expectExceptionObject($error);
        }
        $actual = (new GitObjectScope(
            $factory,
            $filesystem,
            $this->random(),
            self::ROOT,
            new \FastForward\Changelog\Filesystem\PackagePathResolver(
                '/',
            ),
        ))->run(
            self::PROJECT,
            static function (GitRepository $git) use ($failure, $error, $expected): object {
                if ($failure) {
                    throw $error;
                }

                return $expected;
            },
        );
        self::assertSame($expected, $actual);
    }

    /** Failed status and nonabsolute discovery output cannot create a private directory or run the callback. */
    #[TestWith(['/consumer/.git/objects', false])]
    #[TestWith(['relative/objects', true])]
    #[TestWith(['', true])]
    public function testInvalidDiscoveryStopsBeforeFilesystem(string $original, bool $success): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        foreach (['exists', 'mkdir', 'chmod', 'remove'] as $method) {
            $filesystem->expects(self::never())->method($method);
        }
        $factory = $this->discoveryFactory($this->discovery($original, $success));
        $this->expectExceptionMessage('consumer Git object directory could not be verified');
        (new GitObjectScope(
            $factory,
            $filesystem,
            $this->random(),
            self::ROOT,
            new \FastForward\Changelog\Filesystem\PackagePathResolver(
                '/',
            ),
        ))->run(
            self::PROJECT,
            static function (): never {
                self::fail('Invalid discovery must never execute the callback.');
            },
        );
    }

    /** A collision never adopts, chmods or removes another operation's directory. */
    public function testCollisionPreservesExistingDirectory(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('exists')->with($this->objects())->willReturn(true);
        foreach (['mkdir', 'chmod', 'remove'] as $method) {
            $filesystem->expects(self::never())->method($method);
        }
        $this->expectExceptionMessage('private Git object scope already exists');
        (new GitObjectScope($this->discoveryFactory(
            $this->discovery('/consumer/.git/objects'),
        ), $filesystem, $this->random(), self::ROOT, new \FastForward\Changelog\Filesystem\PackagePathResolver(
            '/',
        )))->run(
            self::PROJECT,
            static function (): never {
                self::fail('A collision must never execute the callback.');
            },
        );
    }

    /** Failure while creating child object directories still removes the operation's already-created root. */
    public function testChildDirectoryFailureStillCleansUp(): void
    {
        $objects = $this->objects();
        $error = new RuntimeException('Fixture child mkdir failed.');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('exists')->with($objects)->willReturn(false);
        $created = 0;
        $filesystem->expects(self::exactly(2))->method('mkdir')->willReturnCallback(
            static function (string|iterable $paths, int $mode) use (&$created, $objects, $error): void {
                self::assertSame(0700, $mode);
                if (0 === $created++) {
                    self::assertSame($objects, $paths);

                    return;
                }
                self::assertSame([$objects . '/pack', $objects . '/info'], $paths);

                throw $error;
            },
        );
        $filesystem->expects(self::once())->method('chmod')->with($objects, 0700, 0000, true);
        $filesystem->expects(self::once())->method('remove')->with($objects);
        $this->expectExceptionObject($error);
        (new GitObjectScope($this->discoveryFactory(
            $this->discovery('/consumer/.git/objects'),
        ), $filesystem, $this->random(), self::ROOT, new \FastForward\Changelog\Filesystem\PackagePathResolver(
            '/',
        )))->run(
            self::PROJECT,
            static function (): never {
                self::fail('Incomplete private setup must never execute the callback.');
            },
        );
    }

    /** Injected deterministic entropy never consults a host random source. */
    private function random(): Randomizer
    {
        return new Randomizer(new Mt19937(12345));
    }

    /** Calculates the deterministic expected operation-owned path without reading or writing the filesystem. */
    private function objects(): string
    {
        return self::ROOT . '/fast-forward-changelog-objects-' . bin2hex($this->random()->getBytes(16));
    }

    /** Models one completed Git object-path discovery without starting a subprocess. */
    private function discovery(string $output, bool $success = true): Process
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('run')->willReturn($success ? 0 : 1);
        $process->expects(self::once())->method('getOutput')->willReturn($output);
        $process->expects(self::once())->method('isSuccessful')->willReturn($success);

        return $process;
    }

    /** Requires the single exact read-only discovery command for callbacks that do not query Git themselves. */
    private function discoveryFactory(Process $discovery): ProcessFactoryInterface
    {
        $factory = $this->createMock(ProcessFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with(
            ['git', '-C', self::PROJECT, 'rev-parse', '--path-format=absolute', '--git-path', 'objects'],
        )->willReturn(
            $discovery,
        );

        return $factory;
    }

    /** Verifies private creation modes and exact cleanup paths without touching real files. */
    private function filesystem(): Filesystem
    {
        $objects = $this->objects();
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('exists')->with($objects)->willReturn(false);
        $calls = 0;
        $filesystem->expects(self::exactly(2))->method('mkdir')->willReturnCallback(
            static function (string|iterable $paths, int $mode) use (&$calls, $objects): void {
                self::assertSame(0700, $mode);
                self::assertSame(0 === $calls++ ? $objects : [$objects . '/pack', $objects . '/info'], $paths);
            },
        );
        $filesystem->expects(self::once())->method('chmod')->with($objects, 0700, 0000, true);
        $filesystem->expects(self::once())->method('remove')->with($objects);

        return $filesystem;
    }
}
