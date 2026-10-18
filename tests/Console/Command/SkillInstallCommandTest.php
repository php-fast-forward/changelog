<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\SkillInstallCommand;
use FastForward\Changelog\Skill\SkillInstallerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(SkillInstallCommand::class)]
final class SkillInstallCommandTest extends TestCase
{
    #[DataProvider('states')]
    public function testModesAndJsonKeepOneControlledResult(
        string $state,
        bool $check,
        bool $update,
        bool $dryRun,
        ?string $action,
        int $exit,
    ): void {
        $result = ['state' => $state, 'target' => '.agents/skills/fast-forward-changelog', 'action' => $action];
        $installer = $this->createMock(SkillInstallerInterface::class);
        $installer->expects(self::once())->method('run')->with(
            '/consumer',
            'custom/skills',
            true,
            $check ? 'check' : ($update ? 'update' : 'install'),
            $dryRun,
        )->willReturn(
            $result,
        );
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        self::assertSame(
            $exit,
            new SkillInstallCommand(
                $installer,
            )($io, '/consumer', 'custom/skills', true, $check, $update, $dryRun, true),
        );
        self::assertSame($result, json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function states(): array
    {
        return [[
            'installed', false, false, false, 'install', 0],
            ['current', true, false, false, null, 0],
            ['updated', false, true, false, 'update', 0],
            ['absent', false, false, true, 'install', 0],
            ['outdated', false, true, true, 'update', 0],
            ['absent', true, false, true, null, 1],
            ['diverged', false, true, false, null, 1],
            ['different', false, true, true, null, 1],
        ];
    }

    public function testExclusiveModesFailWithoutResolvingTheInstaller(): void
    {
        $installer = $this->createMock(SkillInstallerInterface::class);
        $installer->expects(self::never())->method('run');
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        self::assertSame(
            2,
            new SkillInstallCommand($installer)($io, check: true, update: true, json: true),
        );
        self::assertStringContainsString(
            'mutually exclusive',
            json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR)['error'],
        );
    }

    public function testHumanStatesAreQualifiedAndDoNotClaimReplacementAfterRefusal(): void
    {
        $installer = $this->createStub(SkillInstallerInterface::class);
        $installer->method('run')->willReturn(['state' => 'unmanaged', 'target' => 'skills/name', 'action' => null]);
        $output = new BufferedOutput();
        self::assertSame(1, new SkillInstallCommand($installer)(new SymfonyStyle(new ArrayInput([]), $output)));
        $text = $output->fetch();
        self::assertStringContainsString('unmanaged; target: skills/name', $text);
        self::assertStringContainsString('No files were replaced', $text);
    }

    public function testHumanSuccessDoesNotAddRefusalDiagnostics(): void
    {
        $installer = $this->createStub(SkillInstallerInterface::class);
        $installer->method('run')->willReturn(['state' => 'current', 'target' => 'skills/name', 'action' => null]);
        $output = new BufferedOutput();
        self::assertSame(0, new SkillInstallCommand($installer)(new SymfonyStyle(new ArrayInput([]), $output)));
        self::assertSame("Skill state: current; target: skills/name\n", $output->fetch());
    }

    #[DataProvider('failures')]
    public function testOperationalAndArgumentErrorsPreserveExitStatus(bool $json, bool $invalid): void
    {
        $installer = $this->createStub(SkillInstallerInterface::class);
        $installer->method('run')->willThrowException(
            $invalid ? new InvalidArgumentException('Invalid target') : new RuntimeException('Recovery retained'),
        );
        $output = new BufferedOutput();
        self::assertSame(
            $invalid ? 2 : 1,
            new SkillInstallCommand($installer)(new SymfonyStyle(new ArrayInput([]), $output), json: $json),
        );
        $text = $output->fetch();
        if ($json) {
            self::assertSame([
                'error' => $invalid ? 'Invalid target' : 'Recovery retained'],
                json_decode(trim($text), true, flags: JSON_THROW_ON_ERROR),
            );
        } else {
            self::assertStringContainsString('Recovery retained', $text);
        }
    }

    public static function failures(): array
    {
        return [[true, true], [true, false], [false, false]];
    }
}
