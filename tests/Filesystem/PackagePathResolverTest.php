<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Filesystem;

use FastForward\Changelog\Filesystem\PackagePathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackagePathResolver::class)]
final class PackagePathResolverTest extends TestCase
{
    #[Test]
    #[TestWith(['CHANGELOG.md', null, '/project/CHANGELOG.md'])]
    #[TestWith(['../CHANGELOG.md', 'packages/library', '/project/packages/CHANGELOG.md'])]
    #[TestWith(['/other/./CHANGELOG.md', 'ignored', '/other/CHANGELOG.md'])]
    #[TestWith(['C:\\project\\.\\CHANGELOG.md', null, 'C:/project/CHANGELOG.md'])]
    #[TestWith(['\\\\server\\share\\CHANGELOG.md', null, '//server/share/CHANGELOG.md'])]
    #[TestWith(['../../outside', '/project', '/outside'])]
    public function absolutePathNormalizesWithoutFilesystemAccess(
        string $path,
        ?string $workingDirectory,
        string $expected,
    ): void {
        self::assertSame($expected, (new PackagePathResolver('/project'))->absolutePath($path, $workingDirectory));
    }

    #[Test]
    #[TestWith(['/project/file', true])]
    #[TestWith(['\\server\\share', true])]
    #[TestWith(['C:\\project\\file', true])]
    #[TestWith(['relative/file', false])]
    public function isAbsoluteRecognizesSupportedRoots(string $path, bool $expected): void
    {
        self::assertSame($expected, (new PackagePathResolver('/project'))->isAbsolute($path));
    }

    #[Test]
    #[TestWith(['/project/src/File.php', '/project', 'src/File.php'])]
    #[TestWith(['/project/src/File.php', '/project/tests', '../src/File.php'])]
    #[TestWith(['/project', '/project', ''])]
    #[TestWith(['D:/project/file', 'C:/project', 'D:/project/file'])]
    #[TestWith(['C:/Project/file', 'c:/Project', 'file'])]
    public function relativePathHandlesCommonAndDifferentRoots(string $path, string $base, string $expected): void
    {
        self::assertSame($expected, (new PackagePathResolver('/project'))->relativePath($path, $base));
    }

    #[Test]
    public function directoryPathReturnsTheRequestedAncestor(): void
    {
        self::assertSame('/project', (new PackagePathResolver('/'))->directoryPath('/project/src/File.php', 2));
        self::assertSame('C:/project', (new PackagePathResolver('/'))->directoryPath('C:\\project\\src\\File.php', 2));
    }
}
