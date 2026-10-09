<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Console\Command\GitHubCommand;
use FastForward\Changelog\Console\GitHubOutputWriterInterface;
use FastForward\Changelog\Console\Input\GitHubInput;
use FastForward\Changelog\Console\Input\ReleaseInput;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

#[CoversClass(GitHubCommand::class)]
#[UsesClass(GitHubInput::class)]
#[UsesClass(ReleaseInput::class)]
final class GitHubCommandTest extends TestCase
{
    /** The actual Console parser maps arguments and flags without shell interpolation or JSON-input scripts. */
    public function testNativeConsoleMappingAndRawOutput(): void
    {
        $runner = $this->createMock(AutomationRunnerInterface::class);
        $expected = new GitHubInput()->values() + ['base-branch' => 'stable', 'managed-branch' => 'release/stable',
            'automation-actor' => 'app[bot]', 'title' => 'Release <info>literal</info>', 'dry-run' => 'true'];
        $runner->expects(self::once())->method('run')->with('version', $expected)->willReturn(['status' => 'planned']);
        $writer = $this->createMock(GitHubOutputWriterInterface::class);
        $writer->expects(self::once())->method('write')->with(['status' => 'planned'])->willReturn('{"status":"<info>literal</info>"}');
        $tester = new CommandTester(new Command('github', new GitHubCommand($runner, $writer)));
        self::assertSame(0, $tester->execute(['operation' => 'version', '--base-branch' => 'stable',
            '--managed-branch' => 'release/stable', '--automation-actor' => 'app[bot]',
            '--title' => 'Release <info>literal</info>', '--dry-run' => 'true'], ['interactive' => false, 'decorated' => true]));
        self::assertSame("{\"status\":\"<info>literal</info>\"}\n", $tester->getDisplay(true));
    }

    /** A history option named operation is distinct from the top-level automation argument. */
    public function testHistoryOperationAndFalseValuesRemainExplicit(): void
    {
        $runner = $this->createMock(AutomationRunnerInterface::class);
        $runner->expects(self::once())->method('run')->with('history', new GitHubInput()->values() + ['operation' => 'format', 'dry-run' => 'false', 'check' => 'true'])->willReturn(['status' => 'planned']);
        $writer = $this->createMock(GitHubOutputWriterInterface::class);
        $writer->expects(self::once())->method('write')->willReturn('{"status":"planned"}');
        $tester = new CommandTester(new Command('github', new GitHubCommand($runner, $writer)));
        self::assertSame(0, $tester->execute(['operation' => 'history', '--operation' => 'format', '--dry-run' => 'false', '--check' => 'true'], ['interactive' => false]));
    }

    /** Controlled validation/JSON failures are distinct from failed operations and never write result outputs. */
    #[DataProvider('failures')]
    public function testFailuresUseJsonAndDistinctExitStatuses(Throwable $failure, int $status): void
    {
        $runner = $this->createMock(AutomationRunnerInterface::class);
        $runner->expects(self::once())->method('run')->willThrowException($failure);
        $writer = $this->createMock(GitHubOutputWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with(json_encode(['error' => $failure->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
        self::assertSame($status, new GitHubCommand($runner, $writer)('check', new GitHubInput(), $output));
    }

    /** Supplies explicit exceptions without consulting filesystem or remote state. */
    public static function failures(): array
    {
        return [[new InvalidArgumentException('Invalid flag.'), 2], [new JsonException('Invalid JSON.'), 2],
            [new RuntimeException('Operation failed.'), 1], [new RuntimeException("Invalid byte: \xff"), 1]];
    }

    /** Persistence failure uses stderr and never retries the completed business operation. */
    public function testOutputFailureUsesTheErrorChannel(): void
    {
        $runner = $this->createMock(AutomationRunnerInterface::class);
        $runner->expects(self::once())->method('run')->willReturn(['status' => 'updated']);
        $writer = $this->createMock(GitHubOutputWriterInterface::class);
        $writer->expects(self::once())->method('write')->willThrowException(new RuntimeException('Output unavailable.'));
        $error = $this->createMock(OutputInterface::class);
        $error->expects(self::once())->method('writeln')->with('{"error":"Output unavailable."}', OutputInterface::OUTPUT_RAW);
        $output = $this->createMock(ConsoleOutputInterface::class);
        $output->expects(self::never())->method('writeln');
        $output->expects(self::once())->method('getErrorOutput')->willReturn($error);
        self::assertSame(1, new GitHubCommand($runner, $writer)('version', new GitHubInput(), $output));
    }
}
