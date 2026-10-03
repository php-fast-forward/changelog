<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Command;

use FastForward\Changelog\Console\Command\StatusCommand;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use FastForward\Changelog\Tests\Console\PlanFixtureTrait;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(StatusCommand::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
final class StatusCommandTest extends TestCase
{
    use PlanFixtureTrait;

    #[Test]
    public function stdoutContainsOnlyTheSharedSummary(): void
    {
        $settings = $this->settings();
        $options = $this->options();
        $plan = $this->plan();
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with($settings->values())->willReturn($options);
        $planner = $this->createMock(ReleasePlannerInterface::class);
        $planner->expects(self::once())->method('plan')->with($options)->willReturn($plan);
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with(json_encode($plan->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
        self::assertSame(0, new StatusCommand($factory, $planner)($settings, $output, true));
    }

    #[Test]
    public function invalidOptionsProduceExitTwoOnErrorChannel(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willThrowException(new InvalidArgumentException('invalid locale'));
        $output = $this->createMock(ConsoleOutputInterface::class);
        $error = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('getErrorOutput')->willReturn($error);
        $output->expects(self::never())->method('writeln');
        $error->expects(self::once())->method('writeln')->with('<error>invalid locale</error>');
        self::assertSame(2, new StatusCommand($factory, $this->createStub(ReleasePlannerInterface::class))($this->settings(), $output));
    }

    #[Test]
    public function operationalFailuresProduceExitOne(): void
    {
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willThrowException(new RuntimeException('Git unavailable'));
        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())->method('writeln')->with('<error>Git unavailable</error>');
        self::assertSame(1, new StatusCommand($factory, $this->createStub(ReleasePlannerInterface::class))($this->settings(), $output));
    }
}
