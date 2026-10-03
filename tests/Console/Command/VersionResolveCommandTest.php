<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\VersionResolveCommand;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(VersionResolveCommand::class)]
final class VersionResolveCommandTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function executePrintsAnExplicitVersionWithoutInference(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $manager->inferNextVersion('unused', null)->shouldNotBeCalled();
        $filesystem->getAbsolutePath('unused', 'unused')->shouldNotBeCalled();
        $tester = new CommandTester(new VersionResolveCommand($manager->reveal(), $filesystem->reveal()));

        self::assertSame(Command::SUCCESS, $tester->execute(['version' => ' 1.2.3 ']));
        self::assertSame("1.2.3\n", $tester->getDisplay(true));
    }

    /** Explicit labels must survive formatter syntax unchanged for machine consumers. */
    #[Test]
    public function executeDoesNotInterpretMarkupInAnExplicitVersion(): void
    {
        $tester = new CommandTester(new VersionResolveCommand($this->prophesize(ChangelogManagerInterface::class)->reveal(), $this->prophesize(PackageFilesystemInterface::class)->reveal()));
        self::assertSame(Command::SUCCESS, $tester->execute(['version' => '<info>stable</info>'], ['decorated' => true]));
        self::assertSame("<info>stable</info>\n", $tester->getDisplay(true));
    }

    #[Test]
    public function executeInfersTheVersionWithAnExplicitBase(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $filesystem->getAbsolutePath('CHANGELOG.md', '.')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $manager->inferNextVersion('/project/CHANGELOG.md', 'v1.2.3')->willReturn('1.3.0')->shouldBeCalledOnce();
        $tester = new CommandTester(new VersionResolveCommand($manager->reveal(), $filesystem->reveal()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--current-version' => 'v1.2.3']));
        self::assertSame("1.3.0\n", $tester->getDisplay(true));
    }

    #[Test]
    public function executeInfersTheVersionWithoutAnExplicitBase(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $filesystem->getAbsolutePath('CHANGELOG.md', '.')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $manager->inferNextVersion('/project/CHANGELOG.md', null)->willReturn('0.1.0')->shouldBeCalledOnce();
        $tester = new CommandTester(new VersionResolveCommand($manager->reveal(), $filesystem->reveal()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame("0.1.0\n", $tester->getDisplay(true));
    }
}
