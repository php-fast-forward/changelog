<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Checker\UnreleasedEntryCheckerInterface;
use FastForward\Changelog\Console\Command\CheckCommand;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckCommand::class)]
final class CheckCommandTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function executeSucceedsWhenPendingChangesExist(): void
    {
        $checker = $this->prophesize(UnreleasedEntryCheckerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $filesystem->getAbsolutePath('CHANGELOG.md', '.')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $filesystem->getAbsolutePath('.', '.')->willReturn('/project')->shouldBeCalledOnce();
        $checker->hasPendingChanges('/project/CHANGELOG.md', 'main', '/project')->willReturn(true)->shouldBeCalledOnce();
        $tester = new CommandTester(new CheckCommand($filesystem->reveal(), $checker->reveal()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--against' => 'main']));
        self::assertStringContainsString('contains unreleased changes', $tester->getDisplay());
    }

    #[Test]
    public function executeFailsWhenNoPendingChangesExist(): void
    {
        $checker = $this->prophesize(UnreleasedEntryCheckerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $filesystem->getAbsolutePath('notes.md', '/project')->willReturn('/project/notes.md')->shouldBeCalledOnce();
        $filesystem->getAbsolutePath('.', '/project')->willReturn('/project')->shouldBeCalledOnce();
        $checker->hasPendingChanges('/project/notes.md', null, '/project')->willReturn(false)->shouldBeCalledOnce();
        $tester = new CommandTester(new CheckCommand($filesystem->reveal(), $checker->reveal()));

        self::assertSame(Command::FAILURE, $tester->execute(['--file' => 'notes.md', '--working-dir' => '/project']));
        self::assertStringContainsString('must add a meaningful entry', $tester->getDisplay());
    }
}
