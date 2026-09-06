<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\EntryCommand;
use FastForward\Changelog\Date\ReleaseDateValidatorInterface;
use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Entry\ChangelogEntryTypesInterface;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use InvalidArgumentException;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EntryCommand::class)]
final class EntryCommandTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function executeAddsADefaultUnreleasedEntry(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $types = $this->prophesize(ChangelogEntryTypesInterface::class);
        $dateValidator = $this->prophesize(ReleaseDateValidatorInterface::class);
        $filesystem->getAbsolutePath('CHANGELOG.md', '.')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $types->fromInput('added')->willReturn(ChangelogEntryType::Added)->shouldBeCalledOnce();
        $dateValidator->validate(Argument::any())->shouldNotBeCalled();
        $manager->addEntry('/project/CHANGELOG.md', ChangelogEntryType::Added, 'Ship it', ChangelogDocument::UNRELEASED_VERSION, null)
            ->shouldBeCalledOnce();
        $tester = new CommandTester(new EntryCommand(
            $manager->reveal(),
            $filesystem->reveal(),
            $types->reveal(),
            $dateValidator->reveal(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute(['message' => 'Ship it']));
        self::assertStringContainsString('Added added changelog entry', $tester->getDisplay());
    }

    #[Test]
    public function executePassesEveryExplicitOptionToTheManager(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $types = $this->prophesize(ChangelogEntryTypesInterface::class);
        $dateValidator = $this->prophesize(ReleaseDateValidatorInterface::class);
        $filesystem->getAbsolutePath('notes.md', '/project')->willReturn('/project/notes.md')->shouldBeCalledOnce();
        $types->fromInput('fixed')->willReturn(ChangelogEntryType::Fixed)->shouldBeCalledOnce();
        $dateValidator->validate('2026-09-05')->shouldBeCalledOnce();
        $manager->addEntry('/project/notes.md', ChangelogEntryType::Fixed, 'Repair', '1.2.3', '2026-09-05')
            ->shouldBeCalledOnce();
        $tester = new CommandTester(new EntryCommand(
            $manager->reveal(),
            $filesystem->reveal(),
            $types->reveal(),
            $dateValidator->reveal(),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'message' => 'Repair',
            '--type' => 'fixed',
            '--release' => '1.2.3',
            '--date' => '2026-09-05',
            '--file' => 'notes.md',
            '--working-dir' => '/project',
        ]));
    }

    #[Test]
    public function executeRejectsAnInvalidDateBeforeResolvingOrMutatingTheChangelog(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $types = $this->prophesize(ChangelogEntryTypesInterface::class);
        $dateValidator = $this->prophesize(ReleaseDateValidatorInterface::class);
        $dateValidator->validate('2026-02-30')
            ->willThrow(new InvalidArgumentException('invalid release date'))
            ->shouldBeCalledOnce();
        $filesystem->getAbsolutePath(Argument::cetera())->shouldNotBeCalled();
        $types->fromInput(Argument::any())->shouldNotBeCalled();
        $manager->addEntry(
            Argument::any(),
            Argument::any(),
            Argument::any(),
            Argument::any(),
            Argument::any(),
        )->shouldNotBeCalled();
        $tester = new CommandTester(new EntryCommand(
            $manager->reveal(),
            $filesystem->reveal(),
            $types->reveal(),
            $dateValidator->reveal(),
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid release date');

        $tester->execute(['message' => 'Repair', '--date' => '2026-02-30']);
    }
}
