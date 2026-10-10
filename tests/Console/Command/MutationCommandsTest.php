<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\BackfillCommand;
use FastForward\Changelog\Console\Command\FormatCommand;
use FastForward\Changelog\Console\Command\VersionCommand;
use FastForward\Changelog\Console\Input\MutationInput;
use FastForward\Changelog\Console\PlanCommandRunnerInterface;
use FastForward\Changelog\Tests\Console\PlanFixtureTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(VersionCommand::class)]
#[CoversClass(BackfillCommand::class)]
#[CoversClass(FormatCommand::class)]
final class MutationCommandsTest extends TestCase
{
    use PlanFixtureTrait;

    #[Test]
    #[DataProvider('commands')]
    public function commandsPreserveOperationModesAndExitCode(string $class, string $operation): void
    {
        $runner = $this->createMock(PlanCommandRunnerInterface::class);
        $input = $this->mutation(true, false);
        $output = $this->createStub(OutputInterface::class);
        $runner->expects(self::once())->method('run')->with($input, $operation, $output)->willReturn(2);
        self::assertSame(2, new $class($runner)($input, $output));
    }

    public static function commands(): array
    {
        return [[
            VersionCommand::class, 'version'],
            [BackfillCommand::class, 'backfill'],
            [FormatCommand::class, 'format'],
        ];
    }

    /** Native short flags preserve each read-only mode through the nested settings DTO. */
    #[DataProvider('shortModes')]
    public function testShortReadOnlyFlagsBindNestedInput(string $class, string $operation, string $short): void
    {
        $runner = $this->createMock(PlanCommandRunnerInterface::class);
        $runner->expects(self::once())->method('run')->with(
            self::callback(static fn(MutationInput $input): bool => '/consumer' === $input->release->workingDirectory
                && ('-d' === $short) === $input->dryRun && ('-c' === $short) === $input->check),
            $operation,
            self::isInstanceOf(OutputInterface::class),
        )->willReturn(2);
        $tester = new CommandTester(new Command(null, new $class($runner)));
        self::assertSame(2, $tester->execute(['-C' => '/consumer', $short => true], ['interactive' => false]));
    }

    /** Exercises every mutation command and both explicit non-writing modes without host state. */
    public static function shortModes(): iterable
    {
        foreach (self::commands() as [$class, $operation]) {
            foreach (['-d', '-c'] as $short) {
                yield [$class, $operation, $short];
            }
        }
    }
}
