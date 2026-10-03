<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\CommandLoader\Factory;

use FastForward\Changelog\Console\CommandLoader\Factory\LazyCommandFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[AsCommand(name: 'fixture', description: 'Fixture callable.')]
final readonly class InvokableFixture
{
    public function __invoke(OutputInterface $output, #[Argument] string $message, #[Option] bool $flag = false): int
    {
        $output->write($message . ($flag ? ' flagged' : ''));
        return 0;
    }
}

#[CoversClass(LazyCommandFactory::class)]
final class LazyCommandFactoryTest extends TestCase
{
    #[Test]
    public function factoryPreservesMetadataAndResolvesPlainInvokableOnlyOnExecution(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(InvokableFixture::class)->willReturn(new InvokableFixture());
        $command = new LazyCommandFactory()->create('fixture', [], 'Fixture callable.', InvokableFixture::class, $container);
        self::assertSame('fixture', $command->getName());
        self::assertSame('Fixture callable.', $command->getDescription());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['message' => 'exact','--flag' => true], ['interactive' => false]));
        self::assertSame('exact flagged', $tester->getDisplay(true));
    }

    #[Test]
    public function nonInvokableServiceFailsOnlyWhenSelected(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->willReturn(new \stdClass());
        $command = new LazyCommandFactory()->create('fixture', [], 'Fixture callable.', 'bad', $container);
        self::assertSame('fixture', $command->getName());
        $this->expectException(LogicException::class);
        $command->getDefinition();
    }
}
