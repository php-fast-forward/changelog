<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Git;

use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Git\GitRepositoryUrlResolver;
use FastForward\Changelog\Git\ProcessFactoryInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(GitRepositoryUrlResolver::class)]
final class GitRepositoryUrlResolverTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    #[TestWith([null])]
    #[TestWith(['  '])]
    public function absentWorkingDirectoryReturnsNull(?string $workingDirectory): void
    {
        $factory = $this->prophesize(ProcessFactoryInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $factory->create(['git', 'config', '--get', 'remote.origin.url'])->shouldNotBeCalled();

        self::assertNull((new GitRepositoryUrlResolver($filesystem->reveal(), $factory->reveal()))->resolve($workingDirectory));
    }

    #[Test]
    public function successfulGitCommandReturnsTrimmedUrl(): void
    {
        [$resolver, $process] = $this->resolverWithProcess();
        $process->isSuccessful()->willReturn(true);
        $process->getOutput()->willReturn(" git@example.com:org/repo.git\n");

        self::assertSame('git@example.com:org/repo.git', $resolver->resolve('/project'));
    }

    #[Test]
    public function failedOrEmptyGitCommandReturnsNull(): void
    {
        [$failedResolver, $failed] = $this->resolverWithProcess();
        $failed->isSuccessful()->willReturn(false);
        self::assertNull($failedResolver->resolve('/project'));

        [$emptyResolver, $empty] = $this->resolverWithProcess();
        $empty->isSuccessful()->willReturn(true);
        $empty->getOutput()->willReturn(" \n");
        self::assertNull($emptyResolver->resolve('/project'));
    }

    /**
     * @return array{GitRepositoryUrlResolver, \Prophecy\Prophecy\ObjectProphecy<Process>}
     */
    private function resolverWithProcess(): array
    {
        $factory = $this->prophesize(ProcessFactoryInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $process = $this->prophesize(Process::class);
        $factory->create(['git', 'config', '--get', 'remote.origin.url'])->willReturn($process->reveal())->shouldBeCalledOnce();
        $filesystem->getAbsolutePath('/project')->willReturn('/project')->shouldBeCalledOnce();
        $process->setWorkingDirectory('/project')->willReturn($process->reveal())->shouldBeCalledOnce();
        $process->run()->shouldBeCalledOnce();

        return [new GitRepositoryUrlResolver($filesystem->reveal(), $factory->reveal()), $process];
    }
}
