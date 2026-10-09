<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Console\PlanCommandRunner;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseApplierInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(PlanCommandRunner::class)]
#[CoversClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
final class PlanCommandRunnerTest extends TestCase
{
    use PlanFixtureTrait;

    #[Test]
    #[DataProvider('modes')]
    public function modeControlsOnlyApplication(
        string $operation,
        string $mode,
        bool $dry,
        bool $check,
        bool $apply,
        int $expected,
    ): void {
        $input = $this->mutation($dry, $check);
        $options = $this->options();
        $plan = $this->plan($mode);
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with($input->release->values())->willReturn($options);
        $planner = $this->createMock(ReleasePlannerInterface::class);
        $planner->expects(self::once())->method('plan')->with($options, $operation)->willReturn($plan);
        $applier = $this->createMock(ReleaseApplierInterface::class);
        $applier->expects($apply ? self::once() : self::never())->method('apply')->with($plan)->willReturn(true);
        $applier->expects($check ? self::once() : self::never())->method('isApplied')->with($plan)->willReturn(
            0 === $expected,
        );
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with(
            json_encode($plan->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            OutputInterface::OUTPUT_RAW,
        );
        self::assertSame(
            $expected,
            new PlanCommandRunner($factory, $planner, $applier)->run($input, $operation, $output),
        );
    }

    public static function modes(): array
    {
        return [
            ['version', 'release', false, false, true, 0],
            ['backfill', 'maintenance', false, false, true, 0],
            ['format', 'maintenance', true, false, false, 0],
            ['version', 'release', true, false, false, 0],
            ['version', 'release', false, true, false, 1],
            ['version', 'release', false, true, false, 0],
            ['format', 'maintenance', false, true, false, 1],
            ['format', 'maintenance', false, true, false, 0],
            ['backfill', 'none', false, true, false, 0],
            ['version', 'none', false, false, false, 0],
        ];
    }

    #[Test]
    public function contradictoryModesFailBeforeResolvingOptions(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $planner = $this->createMock(ReleasePlannerInterface::class);
        $planner->expects(self::never())->method('plan');
        $applier = $this->createMock(ReleaseApplierInterface::class);
        $applier->expects(self::never())->method('apply');
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with(
            '<error>--dry-run and --check are mutually exclusive.</error>',
        );
        self::assertSame(
            2,
            new PlanCommandRunner($factory, $planner, $applier)->run($this->mutation(true, true), 'version', $output),
        );
    }

    #[Test]
    public function RuntimeFailureUsesErrorChannelAndNeverWritesSummary(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willThrowException(new RuntimeException('planning failed'));
        $output = $this->createMock(ConsoleOutputInterface::class);
        $error = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('getErrorOutput')->willReturn($error);
        $output->expects(self::never())->method('writeln');
        $error->expects(self::once())->method('writeln')->with('<error>planning failed</error>');
        self::assertSame(
            1,
            new PlanCommandRunner($factory, $this->createStub(ReleasePlannerInterface::class), $this->createStub(
                ReleaseApplierInterface::class,
            ))->run($this->mutation(), 'version', $output),
        );
    }
}
