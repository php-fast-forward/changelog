<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\BackfillCommand;
use FastForward\Changelog\Console\Command\FormatCommand;
use FastForward\Changelog\Console\Command\VersionCommand;
use FastForward\Changelog\Console\PlanCommandRunnerInterface;
use FastForward\Changelog\Tests\Console\PlanFixtureTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

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
        return [[VersionCommand::class, 'version'], [BackfillCommand::class, 'backfill'], [FormatCommand::class, 'format']];
    }
}
