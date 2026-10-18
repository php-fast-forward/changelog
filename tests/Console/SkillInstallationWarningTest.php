<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Console\SkillInstallationWarning;
use FastForward\Changelog\Skill\SkillInstallerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(SkillInstallationWarning::class)]
final class SkillInstallationWarningTest extends TestCase
{
    #[DataProvider('states')]
    public function testOnlyProvenAgeIsCalledOlderAndOptionalAbsenceIsSilent(string $state, ?string $expected): void
    {
        $installer = $this->createMock(SkillInstallerInterface::class);
        $installer->expects(self::once())->method('run')->with('/consumer', mode: 'check')->willReturn(
            ['state' => $state],
        );
        $error = new BufferedOutput();
        new SkillInstallationWarning($installer, true)->warn('/consumer', $error);
        $message = $error->fetch();
        if (null === $expected) {
            self::assertSame('', $message);
        } else {
            self::assertStringContainsString($expected, $message);
            self::assertSame(1, substr_count($message, "\n"));
        }
        if ('outdated' !== $state) {
            self::assertStringNotContainsString('older than', $message);
        }
    }

    public static function states(): array
    {
        return [['outdated', 'older than'], ['unmanaged', null], ['diverged', null], ['different', null],
            ['recovery-required', null], ['absent', null], ['current', null]];
    }

    public function testDisabledWarningDoesNotInspectFilesystemOrPackagePayload(): void
    {
        $installer = $this->createMock(SkillInstallerInterface::class);
        $installer->expects(self::never())->method('run');
        $error = new BufferedOutput();
        new SkillInstallationWarning($installer, false)->warn('/consumer', $error);
        self::assertSame('', $error->fetch());
    }

    public function testOptionalInspectionFailuresCannotLeakOrBreakCommandOutput(): void
    {
        $installer = $this->createStub(SkillInstallerInterface::class);
        $installer->method('run')->willThrowException(new RuntimeException('private payload bytes'));
        $error = new BufferedOutput();
        new SkillInstallationWarning($installer, true)->warn('/consumer', $error);
        self::assertSame('', $error->fetch());
    }
}
