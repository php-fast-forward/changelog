<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\InstallCommand;
use FastForward\Changelog\Installation\ProjectInstallerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(InstallCommand::class)]
final class InstallCommandTest extends TestCase
{
    /** Parses the public flags through Symfony and applies only after an explicit noninteractive confirmation. */
    public function testExplicitOptionsAreParsedAndAppliedWithoutPrompts(): void
    {
        $calls = [];
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->with('/consumer')->willReturn([
            'state' => 'absent', 'base_branch' => 'main',
        ]);
        $installer->expects(self::exactly(2))->method('run')->willReturnCallback(
            function (string $cwd, ?string $ref, array $choices, bool $skill, bool $dryRun) use (&$calls): array {
                $calls[] = [$cwd, $ref, $choices, $skill, $dryRun];

                return $this->plan($dryRun ? 'planned' : 'installed');
            },
        );
        $tester = $this->tester($installer);

        self::assertSame(0, $tester->execute([
            '-C' => '/consumer', '-r' => 'v0.2.0', '--base-branch' => '2.x', '--publish' => 'false',
            '--set' => [
                'comment-mode=never',
                'prepare-release=false',
                'version-pr-title=release {next_version}',
                'initial-version=0.1.0',
            ],
            '--no-skill' => true, '-y' => true,
        ], ['interactive' => false]));
        $expected = ['/consumer', 'v0.2.0', ['comment-mode' => 'never', 'prepare-release' => false,
            'version-pr-title' => 'release {next_version}', 'initial-version' => '0.1.0',
            'base-branch' => '2.x', 'publish' => false], false];
        self::assertSame([...$expected, true], $calls[0]);
        self::assertSame([...$expected, false], $calls[1]);
        self::assertStringNotContainsString('?', $tester->getDisplay());
        self::assertStringContainsString('Project setup: installed', $tester->getDisplay());
    }

    /** Explains the default journey, gathers deliberate answers and asks before the first write. */
    public function testInteractiveJourneyExplainsPublicationAndConfirmsApplication(): void
    {
        $calls = [];
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->with('.')->willReturn([
            'state' => 'absent', 'base_branch' => 'master',
        ]);
        $installer->expects(self::exactly(2))->method('run')->willReturnCallback(
            function (string $cwd, ?string $ref, array $choices, bool $skill, bool $dryRun) use (&$calls): array {
                $calls[] = [$cwd, $ref, $choices, $skill, $dryRun];

                return $this->plan($dryRun ? 'planned' : 'installed');
            },
        );
        $tester = $this->tester($installer);
        $tester->setInputs(['maintenance/1.x', 'no', 'no', 'yes', 'yes']);

        self::assertSame(0, $tester->execute([], ['interactive' => true]));
        self::assertSame(['.', null, ['base-branch' => 'maintenance/1.x', 'publish' => false], true, true], $calls[0]);
        self::assertSame(['.', null, ['base-branch' => 'maintenance/1.x', 'publish' => false], true, false], $calls[1]);
        $output = $tester->getDisplay();
        self::assertStringContainsString('workflow never merges it for you', $output);
        self::assertStringContainsString('approved tag and GitHub Release', $output);
        self::assertStringContainsString('it does not activate an', $output);
        self::assertStringContainsString('agent or update foreign copies', $output);
        self::assertStringContainsString('Apply this plan without replacing existing configuration?', $output);
    }

    /** Keeps every advanced answer explicit while allowing the optional skill to be declined. */
    public function testAdvancedAnswersAreForwardedAsWorkflowInputs(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [
            'managed-branch' => 'release/1.x', 'runs-on' => '["self-hosted","linux"]', 'comment-mode' => 'on-failure',
            'version-pr-title' => 'chore(release): {current_version} → {next_version}',
            'base-branch' => 'main', 'publish' => true,
        ], false, true)->willReturn($this->plan());
        $tester = $this->tester($installer);
        $tester->setInputs(['', '', 'yes', 'release/1.x', '["self-hosted","linux"]', 'on-failure',
            'chore(release): {current_version} → {next_version}', 'no']);

        self::assertSame(0, $tester->execute(['--dry-run' => true], ['interactive' => true]));
        self::assertStringNotContainsString('Apply this plan', $tester->getDisplay());
    }

    /** Empty advanced answers delegate defaults to the verified selected-release contract. */
    public function testBlankAdvancedAnswersDoNotAddOverrides(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [
            'base-branch' => 'main', 'publish' => true,
        ], false, true)->willReturn($this->plan());
        $tester = $this->tester($installer);
        $tester->setInputs(['', '', 'yes', '', '', '', '']);

        self::assertSame(0, $tester->execute(['--dry-run' => true, '--no-skill' => true], ['interactive' => true]));
    }

    /** Explicit publication values remain booleans without asking the same question again. */
    #[DataProvider('publicationChoices')]
    public function testExplicitPublicationAndBranchSkipRedundantQuestions(string $value, bool $expected): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [
            'base-branch' => 'stable', 'publish' => $expected,
        ], false, true)->willReturn($this->plan());
        $tester = $this->tester($installer);
        $tester->setInputs(['no']);

        self::assertSame(0, $tester->execute([
            '--base-branch' => 'stable', '--publish' => $value, '--no-skill' => true, '--dry-run' => true,
        ], ['interactive' => true]));
        self::assertStringNotContainsString('Release branch (detected locally)', $tester->getDisplay());
        self::assertStringNotContainsString(
            'Publish the approved version PR automatically after you merge it?',
            $tester->getDisplay(),
        );
    }

    /** Supplies the two accepted textual boolean values from the actual CLI contract. */
    public static function publicationChoices(): array
    {
        return [['true', true], ['false', false]];
    }

    /** Explicit reusable overrides preserve their values when the basic guided questions are skipped. */
    public function testSetPublicationAndBranchDoNotTriggerDuplicateQuestions(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [
            'base-branch' => 'stable', 'publish' => false, 'version-pr-title' => 'a=b',
        ], false, true)->willReturn($this->plan());
        $tester = $this->tester($installer);
        $tester->setInputs(['no']);

        self::assertSame(0, $tester->execute([
            '--set' => ['base-branch=stable', 'publish=false', 'version-pr-title=a=b'],
            '--no-skill' => true, '--dry-run' => true,
        ], ['interactive' => true]));
        self::assertStringNotContainsString('Release branch (detected locally)', $tester->getDisplay());
    }

    /** A noninteractive dry-run is read-only and machine output contains only its structured result. */
    public function testJsonPreviewNeverPromptsOrApplies(): void
    {
        $plan = $this->plan();
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [
            'base-branch' => 'main', 'publish' => true,
        ], true, true)->willReturn($plan);
        $tester = $this->tester($installer);

        self::assertSame(0, $tester->execute(['-d' => true, '-j' => true], ['interactive' => true]));
        self::assertSame($plan, json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR));
    }

    /** Machine execution without yes returns a preview and an actionable confirmation-required state. */
    #[DataProvider('jsonModes')]
    public function testNoninteractiveApplicationWithoutYesIsRefused(bool $json): void
    {
        $plan = $this->plan();
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [
            'base-branch' => 'main', 'publish' => true,
        ], true, true)->willReturn($plan);
        $tester = $this->tester($installer);

        self::assertSame(2, $tester->execute($json ? ['--json' => true] : [], ['interactive' => false]));
        if ($json) {
            self::assertSame(['state' => 'confirmation-required', 'plan' => $plan], json_decode(
                trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR,
            ));
        } else {
            self::assertStringContainsString('Project setup: planned', $tester->getDisplay());
            self::assertStringContainsString(
                'Confirmation required. Re-run with --yes in noninteractive mode; no files were written.',
                $tester->getDisplay(),
            );
        }
    }

    /** Supplies human and machine output modes for behavior shared by both. */
    public static function jsonModes(): array
    {
        return [[false], [true]];
    }

    /** A human decline never advances from preview to application. */
    public function testDecliningFinalConfirmationPreservesConfiguration(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'existing', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [], false, true)->willReturn($this->plan());
        $tester = $this->tester($installer);
        $tester->setInputs(['no']);

        self::assertSame(2, $tester->execute(['--no-skill' => true], ['interactive' => true]));
    }

    /** Existing workflow configuration never receives inferred new publication or branch overrides. */
    public function testExistingConfigurationCanInstallOnlyTheExplicitlyChosenSkill(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'existing', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [], true, true)->willReturn($this->plan(
            workflow: ['state' => 'preserved', 'path' => '.github/workflows/custom.yml', 'content' => null],
        ));
        $tester = $this->tester($installer);
        $tester->setInputs(['yes']);

        self::assertSame(0, $tester->execute(['--dry-run' => true], ['interactive' => true]));
        self::assertStringContainsString(
            'Workflow: preserved; path: .github/workflows/custom.yml',
            $tester->getDisplay(),
        );
        self::assertStringNotContainsString('Publish the approved version PR', $tester->getDisplay());
    }

    /** Blocked or partial previews are surfaced without a second write attempt or misleading success. */
    #[DataProvider('blockedPreviews')]
    public function testBlockedAndPartialPreviewsNeverApply(string $state, string $inspection, bool $json): void
    {
        $plan = $this->plan($state);
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => $inspection, 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [], true, true)->willReturn($plan);
        $tester = $this->tester($installer);

        self::assertSame(1, $tester->execute(['--yes' => true, '--json' => $json], ['interactive' => true]));
        if ($json) {
            self::assertSame($plan, json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR));
        } else {
            self::assertStringContainsString('Project setup: ' . $state, $tester->getDisplay());
        }
    }

    /** Supplies independently blocked and partial component states for both output modes. */
    public static function blockedPreviews(): array
    {
        return [['blocked', 'conflict', false], ['blocked', 'conflict', true], ['partial', 'existing', false]];
    }

    /** A discovered conflict does not ask whether to install a skill before reporting its blocked plan. */
    public function testConflictPreviewDoesNotAskQuestions(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'conflict', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [], true, true)->willReturn(
            $this->plan('blocked'),
        );
        $tester = $this->tester($installer);

        self::assertSame(1, $tester->execute(['--dry-run' => true], ['interactive' => true]));
        self::assertStringNotContainsString('Install the project skill', $tester->getDisplay());
    }

    /** A failure discovered while applying remains a failure in the final machine-readable response. */
    public function testPartialApplicationReturnsItsFinalState(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'existing', 'base_branch' => 'main'],
        );
        $installer->expects(self::exactly(2))->method('run')->willReturn($this->plan(), $this->plan('partial'));
        $tester = $this->tester($installer);

        self::assertSame(1, $tester->execute(['--yes' => true, '--json' => true]));
        self::assertSame(
            'partial',
            json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR)['state'],
        );
    }

    /** Confirmed machine application emits one final document, never an extra human or preview response. */
    public function testJsonApplicationProducesOnlyItsVerifiedFinalResult(): void
    {
        $plan = $this->plan('installed');
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::exactly(2))->method('run')->willReturn($this->plan(), $plan);
        $tester = $this->tester($installer);

        self::assertSame(0, $tester->execute(['--yes' => true, '--json' => true], [
            'capture_stderr_separately' => true,
        ]));
        self::assertSame($plan, json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('', $tester->getErrorOutput());
    }

    /** Duplicate or malformed setting names fail before project inspection or filesystem work. */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsFailBeforeInspection(array $settings): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::never())->method('inspect');
        $installer->expects(self::never())->method('run');
        $tester = $this->tester($installer);

        self::assertSame(2, $tester->execute(['--set' => $settings, '--json' => true], [
            'capture_stderr_separately' => true,
        ]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame(['error' => 'Use each --set input once as name=value.'], json_decode(
            trim($tester->getErrorOutput()), true, flags: JSON_THROW_ON_ERROR,
        ));
    }

    /** Supplies missing delimiters, invalid names and repeated keys without executable shell content. */
    public static function invalidSettings(): array
    {
        return [[['publish']], [['=true']], [['Publish=true']], [['invalid_name=true']],
            [['publish=true', 'publish=false']]];
    }

    /** Invalid publication input is classified as usage failure before any preview can be applied. */
    public function testInvalidPublicationFlagIsRejected(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::never())->method('inspect');
        $installer->expects(self::never())->method('run');
        $tester = $this->tester($installer);

        self::assertSame(2, $tester->execute(['--publish' => 'maybe', '--json' => true], [
            'capture_stderr_separately' => true,
        ]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame(['error' => '--publish must be true or false.'], json_decode(
            trim($tester->getErrorOutput()), true, flags: JSON_THROW_ON_ERROR,
        ));
    }

    /** Domain and transport failures retain their status and cannot mix human output into JSON errors. */
    #[DataProvider('failures')]
    public function testFailuresUseTheDedicatedErrorChannel(bool $invalid, bool $json): void
    {
        $message = '<error>Consumer message</error>';
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'absent', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->willThrowException(
            $invalid ? new InvalidArgumentException($message) : new RuntimeException($message),
        );
        $tester = $this->tester($installer);

        self::assertSame($invalid ? 2 : 1, $tester->execute(['--json' => $json, '--dry-run' => true], [
            'interactive' => false, 'capture_stderr_separately' => true,
        ]));
        self::assertSame('', $tester->getDisplay());
        if ($json) {
            self::assertSame(
                ['error' => $message],
                json_decode(trim($tester->getErrorOutput()), true, flags: JSON_THROW_ON_ERROR),
            );
        } else {
            self::assertSame($message, trim($tester->getErrorOutput()));
        }
    }

    /** Supplies invalid-input and operational failures in human and structured modes. */
    public static function failures(): array
    {
        return [[true, false], [true, true], [false, false], [false, true]];
    }

    /** Invalid UTF-8 from a collaborator is substituted so the error channel remains valid JSON. */
    public function testMalformedUtf8ErrorStillProducesAValidJsonError(): void
    {
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willThrowException(new RuntimeException("Invalid \xFF"));
        $installer->expects(self::never())->method('run');
        $tester = $this->tester($installer);

        self::assertSame(1, $tester->execute(['--json' => true], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame(['error' => "Invalid \u{FFFD}"], json_decode(
            trim($tester->getErrorOutput()), true, flags: JSON_THROW_ON_ERROR,
        ));
    }

    /** Human output escapes consumer content and distinguishes installed from bundled skill versions. */
    public function testDisplayQualifiesProvenanceAndEscapesDiagnostics(): void
    {
        $plan = $this->plan();
        $plan['workflow']['content'] = '<error>name: custom</error>';
        $plan['skill'] = ['state' => 'current', 'installed_source' => ['version' => '1.0.0'],
            'source' => ['version' => '2.0.0']];
        $plan['diagnostics'] = ['<info>Existing configuration was preserved</info>'];
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'existing', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->willReturn($plan);
        $tester = $this->tester($installer);

        self::assertSame(0, $tester->execute(['--dry-run' => true], ['interactive' => false]));
        self::assertStringContainsString('<error>name: custom</error>', $tester->getDisplay());
        self::assertStringContainsString('Skill: current; installed: 1.0.0; bundled: 2.0.0', $tester->getDisplay());
        self::assertStringContainsString(
            '<info>Existing configuration was preserved</info>',
            $tester->getDisplay(),
        );
    }

    /** Presentation accepts a skipped skill without suggesting an installation or requiring provenance. */
    public function testSkippedSkillDoesNotProduceProvenanceOutput(): void
    {
        $plan = $this->plan(workflow: ['state' => 'preserved', 'path' => '.github/workflows/changelog.yml']);
        $plan['skill'] = null;
        $installer = $this->createMock(ProjectInstallerInterface::class);
        $installer->expects(self::once())->method('inspect')->willReturn(
            ['state' => 'existing', 'base_branch' => 'main'],
        );
        $installer->expects(self::once())->method('run')->with('.', null, [], false, true)->willReturn($plan);
        $tester = $this->tester($installer);

        self::assertSame(0, $tester->execute(['--no-skill' => true, '--dry-run' => true], ['interactive' => false]));
        self::assertStringNotContainsString('Skill:', $tester->getDisplay());
    }

    /** Wraps the real invokable adapter so options, booleans, arrays and SymfonyStyle injection are exercised. */
    private function tester(ProjectInstallerInterface $installer): CommandTester
    {
        return new CommandTester(new Command(null, new InstallCommand($installer)));
    }

    /** Creates a consumer-neutral response without touching a filesystem, process, network or home directory. */
    private function plan(string $state = 'planned', ?array $workflow = null): array
    {
        return ['state' => $state, 'workflow' => $workflow ?? ['state' => 'create',
            'path' => '.github/workflows/changelog.yml', 'content' => "name: Changelog\n"],
            'skill' => ['state' => 'absent', 'installed_source' => null, 'source' => ['version' => '0.2.0']],
            'diagnostics' => []];
    }
}
