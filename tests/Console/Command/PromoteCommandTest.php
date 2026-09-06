<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use DateTimeImmutable;
use FastForward\Changelog\Console\Command\PromoteCommand;
use FastForward\Changelog\Date\ReleaseDateValidatorInterface;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use InvalidArgumentException;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PromoteCommand::class)]
final class PromoteCommandTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function executeUsesAnExplicitDate(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $clock = $this->prophesize(ClockInterface::class);
        $dateValidator = $this->prophesize(ReleaseDateValidatorInterface::class);
        $filesystem->getAbsolutePath('CHANGELOG.md', '.')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $clock->now()->shouldNotBeCalled();
        $dateValidator->validate('2026-09-05')->shouldBeCalledOnce();
        $manager->promote('/project/CHANGELOG.md', '1.2.3', '2026-09-05')->shouldBeCalledOnce();
        $tester = new CommandTester(new PromoteCommand(
            $manager->reveal(),
            $filesystem->reveal(),
            $clock->reveal(),
            $dateValidator->reveal(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['version' => '1.2.3', '--date' => '2026-09-05']));
        self::assertStringContainsString('Promoted Unreleased', $tester->getDisplay());
    }

    #[Test]
    public function executeUsesTheInjectedClockWhenDateIsOmitted(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $clock = $this->prophesize(ClockInterface::class);
        $dateValidator = $this->prophesize(ReleaseDateValidatorInterface::class);
        $filesystem->getAbsolutePath('notes.md', '/project')->willReturn('/project/notes.md')->shouldBeCalledOnce();
        $clock->now()->willReturn(new DateTimeImmutable('2026-09-05T12:00:00Z'))->shouldBeCalledOnce();
        $dateValidator->validate('2026-09-05')->shouldBeCalledOnce();
        $manager->promote('/project/notes.md', '2.0.0', '2026-09-05')->shouldBeCalledOnce();
        $tester = new CommandTester(new PromoteCommand(
            $manager->reveal(),
            $filesystem->reveal(),
            $clock->reveal(),
            $dateValidator->reveal(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'version' => '2.0.0',
            '--file' => 'notes.md',
            '--working-dir' => '/project',
        ]));
    }

    #[Test]
    public function executeRejectsAnInvalidExplicitDateBeforeResolvingOrPromotingTheChangelog(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $clock = $this->prophesize(ClockInterface::class);
        $dateValidator = $this->prophesize(ReleaseDateValidatorInterface::class);
        $clock->now()->shouldNotBeCalled();
        $dateValidator->validate('05-09-2026')
            ->willThrow(new InvalidArgumentException('invalid release date'))
            ->shouldBeCalledOnce();
        $filesystem->getAbsolutePath(Argument::cetera())->shouldNotBeCalled();
        $manager->promote(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();
        $tester = new CommandTester(new PromoteCommand(
            $manager->reveal(),
            $filesystem->reveal(),
            $clock->reveal(),
            $dateValidator->reveal(),
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid release date');

        $tester->execute(['version' => '1.2.3', '--date' => '05-09-2026']);
    }
}
