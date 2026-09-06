<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Filesystem;

use FastForward\Changelog\Filesystem\PackageFilesystem;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(PackageFilesystem::class)]
final class PackageFilesystemTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function existsDelegatesUsingTheResolvedPath(): void
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $resolver = $this->prophesize(PackagePathResolverInterface::class);
        $resolver->absolutePath('CHANGELOG.md', '/project')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $filesystem->exists('/project/CHANGELOG.md')->willReturn(true)->shouldBeCalledOnce();

        self::assertTrue((new PackageFilesystem($filesystem->reveal(), $resolver->reveal()))->exists('CHANGELOG.md', '/project'));
    }

    #[Test]
    public function readFileDelegatesWithoutRealIo(): void
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $resolver = $this->prophesize(PackagePathResolverInterface::class);
        $resolver->absolutePath('CHANGELOG.md', null)->willReturn('/virtual/CHANGELOG.md')->shouldBeCalledOnce();
        $filesystem->readFile('/virtual/CHANGELOG.md')->willReturn('contents')->shouldBeCalledOnce();

        self::assertSame('contents', (new PackageFilesystem($filesystem->reveal(), $resolver->reveal()))->readFile('CHANGELOG.md'));
    }

    #[Test]
    public function dumpFileDelegatesWithoutRealIo(): void
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $resolver = $this->prophesize(PackagePathResolverInterface::class);
        $resolver->absolutePath('CHANGELOG.md', '/project')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $filesystem->dumpFile('/project/CHANGELOG.md', 'contents')->shouldBeCalledOnce();

        (new PackageFilesystem($filesystem->reveal(), $resolver->reveal()))->dumpFile('CHANGELOG.md', 'contents', '/project');
    }

    #[Test]
    public function mkdirDelegatesModeAndResolvedPath(): void
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $resolver = $this->prophesize(PackagePathResolverInterface::class);
        $resolver->absolutePath('build', '/project')->willReturn('/project/build')->shouldBeCalledOnce();
        $filesystem->mkdir('/project/build', 0o750)->shouldBeCalledOnce();

        (new PackageFilesystem($filesystem->reveal(), $resolver->reveal()))->mkdir('build', 0o750, '/project');
    }

    #[Test]
    public function pathHelpersDelegateToTheResolver(): void
    {
        $filesystem = $this->prophesize(Filesystem::class);
        $resolver = $this->prophesize(PackagePathResolverInterface::class);
        $resolver->absolutePath('file', '/base')->willReturn('/base/file')->shouldBeCalledOnce();
        $resolver->directoryPath('/base/file', 2)->willReturn('/')->shouldBeCalledOnce();
        $packageFilesystem = new PackageFilesystem($filesystem->reveal(), $resolver->reveal());

        self::assertSame('/base/file', $packageFilesystem->getAbsolutePath('file', '/base'));
        self::assertSame('/', $packageFilesystem->getDirectory('/base/file', 2));
    }
}
