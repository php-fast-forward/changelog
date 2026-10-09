<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Factory;

use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactory;
use FastForward\Changelog\Release\ReleaseOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseOptionsFactory::class)]
#[CoversClass(ReleaseOptions::class)]
final class ReleaseOptionsFactoryTest extends TestCase
{
    public function testDefaultsResolveTheConsumerRootWithoutAnyConfigurationFile(): void
    {
        $options = $this->factory()->create();
        self::assertSame('/consumer', $options->workingDirectory);
        self::assertSame('.changelog', $options->fragmentDirectory);
        self::assertSame('CHANGELOG.md', $options->changelogFile);
        self::assertSame('en', $options->locale);
        self::assertSame('keep-a-changelog', $options->template);
        self::assertSame('HEAD', $options->baseRef);
        self::assertSame('v', $options->tagPrefix);
        self::assertNull($options->repository);
        self::assertSame('auto', $options->source);
    }

    public function testExplicitSettingsOverrideDefaultsAndNormalizeManagedSeparators(): void
    {
        $options = $this->factory()->create([
            'locale' => 'pt-BR', 'fragmentDirectory' => 'changes\\pending',
            'changelogFile' => 'docs\\CHANGES.md', 'template' => 'custom.php',
            'baseRef' => 'origin/stable', 'tagPrefix' => '', 'source' => 'tags',
            'repository' => 'owner/project',
        ]);
        self::assertSame('pt-BR', $options->locale);
        self::assertSame('changes/pending', $options->fragmentDirectory);
        self::assertSame('docs/CHANGES.md', $options->changelogFile);
        self::assertSame('custom.php', $options->template);
        self::assertSame('origin/stable', $options->baseRef);
        self::assertSame('', $options->tagPrefix);
        self::assertSame('tags', $options->source);
        self::assertSame('owner/project', $options->repository);
        self::assertSame('github', $this->factory()->create(['source' => 'github', 'tagPrefix' => 'release/'])->source);
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidInputsFailBeforeIo(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->factory()->create($values);
    }

    public function testDistinctDirectoryPrefixesRetainTheRequestedCase(): void
    {
        $options = $this->factory()->create([
            'fragmentDirectory' => 'Changes/Pending',
            'changelogFile' => 'changes/pending-history/CHANGES.md',
        ]);
        self::assertSame('Changes/Pending', $options->fragmentDirectory);
        self::assertSame('changes/pending-history/CHANGES.md', $options->changelogFile);
    }

    /** Supported prefixes must form valid complete Git tag refs without changing their spelling. */
    #[DataProvider('validTagPrefixes')]
    public function testValidTagNamespacesPreserveTheConfiguredPrefix(string $prefix): void
    {
        self::assertSame($prefix, $this->factory()->create(['tagPrefix' => $prefix])->tagPrefix);
    }

    /** Supplies valid namespaces and prefixes whose suffix is safe only after appending the version. */
    public static function validTagPrefixes(): array
    {
        return [
            [''], ['v'], ['release/'], ['release/v'], ['release/stable/v'],
            ['release/-v'], ['release/_v'], ['release./v'], ['v.'],
            ['v.lock'], ['release/v.lock'], ['release.LOCK/v'],
        ];
    }

    /** Invalid Git ref syntax must fail before the working-directory adapter resolves a repository path. */
    #[DataProvider('invalidTagPrefixes')]
    public function testInvalidGitTagPrefixesFailBeforeResolvingTheWorkingDirectory(string $prefix): void
    {
        $paths = $this->createMock(PackagePathResolverInterface::class);
        $paths->method('isAbsolute')->willReturn(false);
        $paths->expects(self::never())->method('absolutePath');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tag prefix must use supported characters and form a valid Git tag reference.');
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::never())->method('isRepository');
        $git->expects(self::never())->method('originUrl');
        new ReleaseOptionsFactory($paths, $git)->create(['tagPrefix' => $prefix]);
    }

    /** Covers empty/dot/lock components, revision expressions and every Git-prohibited character family. */
    public static function invalidTagPrefixes(): array
    {
        return [
            ['release//'], ['release//v'], ['release///v'], ['v..'],
            ['release/..v'], ['release/.hidden/v'], ['release/./v'],
            ['release/../v'], ['release.lock/'], ['release.lock/v'],
            ['release/.lock/v'], ['/v'], ['v~'], ['v^'], ['v:'],
            ['v?'], ['v*'], ['v['], ['v\\'], ['v@{'], ['v '],
            ["v\t"], ["v\n"], ["v\r"], ["v\x1f"], ["v\x7f"],
        ];
    }

    public static function invalidSettings(): array
    {
        return [
            [['unknown' => 'value']], [['locale' => 'fr']], [['locale' => false]],
            [['locale' => '']], [['template' => "x\0y"]], [['source' => 'guess']],
            [['tagPrefix' => '-bad']], [['baseRef' => '-head']], [['baseRef' => "HEAD\nnext"]],
            [['repository' => 'https://example.com/project']], [['repository' => '']],
            [['fragmentDirectory' => '/outside']], [['fragmentDirectory' => '../outside']],
            [['fragmentDirectory' => 'a/../b']], [['fragmentDirectory' => '.']],
            [['changelogFile' => '-file']], [['workingDirectory' => null]],
            [['template' => 'custom.json']], [['template' => '/outside.php']],
            [['template' => '../outside.php']], [['template' => '-custom.php']],
            [['fragmentDirectory' => '.changelog/']], [['fragmentDirectory' => './.changelog']],
            [['changelogFile' => './CHANGELOG.md']], [['changelogFile' => 'docs//CHANGES.md']],
            [['template' => './presentation.php']],
            [['changelogFile' => '.changelog/release-plan.json']],
            [['changelogFile' => '.changelog/history.md']], [['changelogFile' => '.changelog']],
            [['changelogFile' => '.CHANGELOG']],
            [['fragmentDirectory' => '.CHANGELOG', 'changelogFile' => '.changelog']],
            [['changelogFile' => '.CHANGELOG/release-plan.json']],
            [['fragmentDirectory' => '.ChangeLog', 'changelogFile' => '.changelog/HISTORY.md']],
            [['fragmentDirectory' => 'Packages/Module/.Changelog', 'changelogFile' => 'packages/module/.CHANGELOG/history.md']],
            [['fragmentDirectory' => 'PACKAGES\\Module\\.ChangeLog', 'changelogFile' => 'packages/module/.changelog/release-plan.json']],
        ];
    }

    /** Canonical GitHub origins capture the publication target while default history stays offline. */
    #[DataProvider('githubOrigins')]
    public function testGitHubOriginBindsTheReceiptTargetWithoutSelectingNetworkHistory(string $origin): void
    {
        $options = $this->factory($origin)->create();
        self::assertSame('Owner/Project', $options->repository);
        self::assertSame('tags', $options->source);
        self::assertSame('tags', $this->factory($origin)->create(['source' => 'tags'])->source);
        self::assertSame('github', $this->factory($origin)->create(['source' => 'github'])->source);
    }

    /** HTTPS, explicit SSH and scp-like GitHub URLs carry the same case-preserved owner/name. */
    public static function githubOrigins(): array
    {
        return [
            ['https://github.com/Owner/Project.git'], ['https://github.com/Owner/Project'],
            ['ssh://git@github.com/Owner/Project.git'], ['ssh://git@github.com/Owner/Project'],
            ['git@github.com:Owner/Project.git'], ['git@github.com:Owner/Project'],
            ['https://GITHUB.COM/Owner/Project.git'],
        ];
    }

    /** Unsupported hosts, aliases, credentials and ambiguous URLs remain local-only until explicitly configured. */
    #[DataProvider('nonCanonicalOrigins')]
    public function testLocalAndNonCanonicalOriginsDoNotGuessAPublicationTarget(?string $origin): void
    {
        $options = $this->factory($origin, true)->create();
        self::assertNull($options->repository);
        self::assertSame('auto', $options->source);
    }

    /** Absence and every unsupported URL shape preserve offline defaults. */
    public static function nonCanonicalOrigins(): array
    {
        return [[null], ['/local/repository'], ['https://example.com/Owner/Project.git'],
            ['git@github-personal:Owner/Project.git'], ['https://github.com/Owner/Project.git?token=synthetic'],
            ['https://github.com/Owner/Project#fragment'], ['https://user:synthetic@github.com/Owner/Project.git'],
            ['https://github.com/Owner/Project/extra'], ['https://github.com/Owner/Project.git\n']];
    }

    /** Explicit owner/name is authoritative and MUST NOT inspect an unrelated local remote. */
    public function testExplicitRepositoryNeverConsultsGitOrigin(): void
    {
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('isAbsolute')->willReturn(false);
        $paths->method('absolutePath')->willReturn('/consumer');
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::never())->method('isRepository');
        $git->expects(self::never())->method('originUrl');
        $options = new ReleaseOptionsFactory($paths, $git)->create(['repository' => 'Selected/Target']);
        self::assertSame('Selected/Target', $options->repository);
        self::assertSame('auto', $options->source);
    }

    /** Injects repository-local evidence; this unit fixture performs no Git or filesystem I/O. */
    private function factory(?string $origin = null, bool $repository = false): ReleaseOptionsFactory
    {
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturn('/consumer');
        $paths->method('isAbsolute')->willReturnCallback(static fn(string $path): bool => str_starts_with($path, '/'));
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('isRepository')->willReturn($repository || null !== $origin);
        $git->method('originUrl')->willReturn($origin);

        return new ReleaseOptionsFactory($paths, $git);
    }
}
