<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\CommandLoader;

use FastForward\Changelog\Console\Command\AddCommand;
use FastForward\Changelog\Console\Command\StatusCommand;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactory;
use FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactoryInterface;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(ChangelogCommandLoader::class)]
#[UsesClass(LazyCommandFactory::class)]
#[UsesClass(StatusCommand::class)]
#[UsesClass(ReleaseInput::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
final class ChangelogCommandLoaderTest extends TestCase
{
    #[Test]
    public function namesAndCanonicalMetadataNeverResolveServices(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $factory = $this->createMock(LazyCommandFactoryInterface::class);
        $command = $this->createStub(Command::class);
        $factory->expects(self::once())->method('create')->with('add', [], 'Create one changelog fragment with optional release metadata.', AddCommand::class, $container)->willReturn($command);
        $loader = new ChangelogCommandLoader($container, $factory);
        self::assertSame(['add','check','status','version','notes','publish','backfill','format','github'], $loader->getNames());
        self::assertTrue($loader->has('add'));
        self::assertFalse($loader->has('changelog:entry'));
        self::assertFalse($loader->has('unknown'));
        self::assertSame($command, $loader->get('add'));
        self::assertSame($command, $loader->get('add'));
    }

    #[Test]
    public function unknownCommandFailsWithoutConsultingContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $loader = new ChangelogCommandLoader($container, $this->createStub(LazyCommandFactoryInterface::class));
        $this->expectException(CommandNotFoundException::class);
        $loader->get('unknown');
    }

    #[Test]
    public function listAndGeneralHelpRemainUsableWithEveryServiceBroken(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $application = new Application('Changelog', 'test');
        $application->setAutoExit(false);
        $application->setCommandLoader(new ChangelogCommandLoader($container, new LazyCommandFactory()));
        $tester = new ApplicationTester($application);
        self::assertSame(0, $tester->run(['command' => 'list','--raw' => true], ['interactive' => false]));
        self::assertStringContainsString('backfill', $tester->getDisplay(true));
        self::assertSame(0, $tester->run(['command' => 'help'], ['interactive' => false]));
        self::assertStringContainsString('Usage:', $tester->getDisplay(true));
    }

    #[Test]
    public function oneBrokenDependencyGraphDoesNotPreventAnotherCommandFromRunning(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $options = new ReleaseOptions('/consumer');
        $factory = $this->createMock(ReleaseOptionsFactoryInterface::class);
        $factory->expects(self::once())->method('create')->willReturn($options);
        $plan = new ReleasePlan(
            $options,
            'id',
            null,
            '0.0.0',
            null,
            null,
            [],
            [],
            '/consumer/CHANGELOG.md',
            null,
            '',
            "Raw <info>notes</info>\n",
            '/consumer/.changelog/release-plan.json',
            null,
            '{}',
        );
        $planner = $this->createMock(ReleasePlannerInterface::class);
        $planner->expects(self::once())->method('plan')->willReturn($plan);
        $status = new StatusCommand($factory, $planner);
        $container->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $service) use ($status): object {
            if (AddCommand::class === $service) {
                throw new RuntimeException('add graph unavailable');
            }return $status;
        });
        $application = new Application('Changelog', 'test');
        $application->setAutoExit(false);
        $application->setCommandLoader(new ChangelogCommandLoader($container, new LazyCommandFactory()));
        $tester = new ApplicationTester($application);
        self::assertSame(1, $tester->run(['command' => 'add','message' => 'test'], ['interactive' => false]));
        self::assertStringContainsString('add graph unavailable', $tester->getDisplay(true));
        self::assertSame(0, $tester->run(['command' => 'status'], ['interactive' => false]));
        self::assertSame($plan->summary(), json_decode(trim($tester->getDisplay(true)), true, 512, JSON_THROW_ON_ERROR));
    }
}
