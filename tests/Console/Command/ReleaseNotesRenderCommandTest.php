<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\ReleaseNotesRenderCommand;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReleaseNotesRenderCommand::class)]
final class ReleaseNotesRenderCommandTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function executeWritesReleaseNotesToStandardOutput(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $filesystem->getAbsolutePath('CHANGELOG.md', '.')->willReturn('/project/CHANGELOG.md')->shouldBeCalledOnce();
        $manager->renderReleaseNotes('/project/CHANGELOG.md', '1.2.3')
            ->willReturn("<info>literal Markdown</info>\n")
            ->shouldBeCalledOnce();
        $tester = new CommandTester(new ReleaseNotesRenderCommand($manager->reveal(), $filesystem->reveal()));

        self::assertSame(Command::SUCCESS, $tester->execute(['version' => '1.2.3']));
        self::assertSame("<info>literal Markdown</info>\n", $tester->getDisplay());
    }

    #[Test]
    public function executeWritesReleaseNotesThroughTheFilesystemAbstraction(): void
    {
        $manager = $this->prophesize(ChangelogManagerInterface::class);
        $filesystem = $this->prophesize(PackageFilesystemInterface::class);
        $filesystem->getAbsolutePath('notes.md', '/project')->willReturn('/project/notes.md')->shouldBeCalledOnce();
        $filesystem->getAbsolutePath('release.md', '/project')->willReturn('/project/release.md')->shouldBeCalledOnce();
        $manager->renderReleaseNotes('/project/notes.md', '2.0.0')->willReturn('notes')->shouldBeCalledOnce();
        $filesystem->dumpFile('/project/release.md', 'notes')->shouldBeCalledOnce();
        $tester = new CommandTester(new ReleaseNotesRenderCommand($manager->reveal(), $filesystem->reveal()));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'version' => '2.0.0',
            '--file' => 'notes.md',
            '--output-file' => 'release.md',
            '--working-dir' => '/project',
        ]));
        self::assertStringContainsString('rendered to /project/release.md', $tester->getDisplay());
    }
}
