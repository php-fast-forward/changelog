<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\CommandLoader;

use FastForward\Changelog\Console\CommandLoader\LazyCommandFactory;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(LazyCommandFactory::class)]
final class LazyCommandFactoryTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function createDefersContainerResolutionUntilExecution(): void
    {
        $container = $this->prophesize(ContainerInterface::class);
        $command = $this->prophesize(Command::class);
        $commandInstance = $command->reveal();
        $container->get('service')->willReturn($commandInstance)->shouldBeCalledOnce();
        $command->setName(Argument::any())->willReturn($commandInstance);
        $command->setAliases(Argument::any())->willReturn($commandInstance);
        $command->setHidden(Argument::any())->willReturn($commandInstance);
        $command->setDescription(Argument::any())->willReturn($commandInstance);
        $command->setHelp(Argument::any())->willReturn($commandInstance);
        $definition = new InputDefinition();
        $command->getDefinition()->willReturn($definition);
        $command->setDefinition($definition)->willReturn($commandInstance);
        $command->setApplication(null)->shouldBeCalledOnce();
        $command->run(Argument::type(ArrayInput::class), Argument::type(NullOutput::class))
            ->willReturn(Command::SUCCESS)
            ->shouldBeCalledOnce();

        $lazy = (new LazyCommandFactory())->create(
            'example',
            ['alias'],
            'Description',
            'service',
            $container->reveal(),
        );

        self::assertInstanceOf(LazyCommand::class, $lazy);
        self::assertSame('example', $lazy->getName());
        self::assertSame(['alias'], $lazy->getAliases());
        self::assertSame('Description', $lazy->getDescription());
        self::assertSame(Command::SUCCESS, $lazy->run(new ArrayInput([]), new NullOutput()));
    }
}
