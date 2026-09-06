<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Git;

use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitFileNotFoundException;
use FastForward\Changelog\Git\GitFileReader;
use FastForward\Changelog\Git\ProcessFactoryInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(GitFileReader::class)]
final class GitFileReaderTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function showMakesAnAbsolutePathRelativeAndReturnsTrimmedOutput(): void
    {
        $factory = $this->prophesize(ProcessFactoryInterface::class);
        $paths = $this->prophesize(PackagePathResolverInterface::class);
        $process = $this->prophesize(Process::class);
        $paths->isAbsolute('/project/CHANGELOG.md')->willReturn(true)->shouldBeCalledOnce();
        $paths->relativePath('/project/CHANGELOG.md', '/project')->willReturn('CHANGELOG.md')->shouldBeCalledOnce();
        $factory->create(['git', 'show', 'main:CHANGELOG.md'])->willReturn($process->reveal())->shouldBeCalledOnce();
        $process->setWorkingDirectory('/project')->willReturn($process->reveal())->shouldBeCalledOnce();
        $process->run()->shouldBeCalledOnce();
        $process->isSuccessful()->willReturn(true)->shouldBeCalledOnce();
        $process->getOutput()->willReturn(" baseline\n")->shouldBeCalledOnce();

        self::assertSame('baseline', (new GitFileReader($factory->reveal(), $paths->reveal()))
            ->show('main', '/project/CHANGELOG.md', '/project'));
    }

    #[Test]
    public function showLeavesRelativePathsAndWorkingDirectoryUntouched(): void
    {
        $factory = $this->prophesize(ProcessFactoryInterface::class);
        $paths = $this->prophesize(PackagePathResolverInterface::class);
        $process = $this->prophesize(Process::class);
        $paths->isAbsolute('CHANGELOG.md')->shouldNotBeCalled();
        $factory->create(['git', 'show', 'main:CHANGELOG.md'])->willReturn($process->reveal());
        $process->setWorkingDirectory('/project')->shouldNotBeCalled();
        $process->run()->shouldBeCalledOnce();
        $process->isSuccessful()->willReturn(true);
        $process->getOutput()->willReturn('baseline');

        self::assertSame('baseline', (new GitFileReader($factory->reveal(), $paths->reveal()))
            ->show('main', 'CHANGELOG.md'));
    }

    #[Test]
    public function showThrowsSpecificExceptionForAPathMissingFromTheReference(): void
    {
        [$reader, $process] = $this->failingReader();
        $process->getErrorOutput()->willReturn("fatal: path 'CHANGELOG.md' exists on disk, but not in 'main'");

        $this->expectException(GitFileNotFoundException::class);

        $reader->show('main', 'CHANGELOG.md');
    }

    #[Test]
    public function showPropagatesGitErrorOutput(): void
    {
        [$reader, $process] = $this->failingReader();
        $process->getErrorOutput()->willReturn('fatal: bad revision');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fatal: bad revision');

        $reader->show('main', 'CHANGELOG.md');
    }

    #[Test]
    public function showProvidesAFallbackForEmptyGitErrorOutput(): void
    {
        [$reader, $process] = $this->failingReader();
        $process->getErrorOutput()->willReturn('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Git could not read the requested changelog baseline.');

        $reader->show('main', 'CHANGELOG.md');
    }

    /**
     * @return array{GitFileReader, \Prophecy\Prophecy\ObjectProphecy<Process>}
     */
    private function failingReader(): array
    {
        $factory = $this->prophesize(ProcessFactoryInterface::class);
        $paths = $this->prophesize(PackagePathResolverInterface::class);
        $process = $this->prophesize(Process::class);
        $factory->create(['git', 'show', 'main:CHANGELOG.md'])->willReturn($process->reveal());
        $process->run()->shouldBeCalledOnce();
        $process->isSuccessful()->willReturn(false);

        return [new GitFileReader($factory->reveal(), $paths->reveal()), $process];
    }
}
