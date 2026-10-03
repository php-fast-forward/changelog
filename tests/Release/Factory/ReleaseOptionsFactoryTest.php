<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Factory;

use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
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

    private function factory(): ReleaseOptionsFactory
    {
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturn('/consumer');
        $paths->method('isAbsolute')->willReturnCallback(static fn(string $path): bool => str_starts_with($path, '/'));
        return new ReleaseOptionsFactory($paths);
    }
}
