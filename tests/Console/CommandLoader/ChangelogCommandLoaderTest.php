<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\CommandLoader;

use FastForward\Changelog\Console\Command\VersionResolveCommand;
use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\LazyCommandFactoryInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;

#[CoversClass(ChangelogCommandLoader::class)]
final class ChangelogCommandLoaderTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function metadataLookupDoesNotResolveServices(): void
    {
        $container = $this->prophesize(ContainerInterface::class);
        $factory = $this->prophesize(LazyCommandFactoryInterface::class);
        $container->get('anything')->shouldNotBeCalled();
        $factory->create('anything', [], 'anything', Command::class, $container->reveal())->shouldNotBeCalled();
        $loader = new ChangelogCommandLoader($container->reveal(), $factory->reveal());

        self::assertTrue($loader->has('changelog:entry'));
        self::assertTrue($loader->has('changelog:next-version'));
        self::assertFalse($loader->has('unknown'));
        self::assertSame(
            [
                'changelog:check',
                'changelog:entry',
                'changelog:promote',
                'changelog:resolve-version',
                'changelog:next-version',
                'changelog:render-release-notes',
                'changelog:show',
                'changelog:release-notes',
            ],
            $loader->getNames(),
        );
    }

    #[Test]
    public function getCreatesAndCachesOneLazyCommandForCanonicalNameAndAlias(): void
    {
        $container = $this->prophesize(ContainerInterface::class);
        $factory = $this->prophesize(LazyCommandFactoryInterface::class);
        $command = $this->prophesize(Command::class)->reveal();
        $factory->create(
            'changelog:resolve-version',
            ['changelog:next-version'],
            'Resolve the release version from input or infer it from Unreleased entries.',
            VersionResolveCommand::class,
            $container->reveal(),
        )->willReturn($command)->shouldBeCalledOnce();
        $loader = new ChangelogCommandLoader($container->reveal(), $factory->reveal());

        self::assertSame($command, $loader->get('changelog:next-version'));
        self::assertSame($command, $loader->get('changelog:resolve-version'));
    }

    #[Test]
    public function getRejectsUnknownCommands(): void
    {
        $loader = new ChangelogCommandLoader(
            $this->prophesize(ContainerInterface::class)->reveal(),
            $this->prophesize(LazyCommandFactoryInterface::class)->reveal(),
        );

        $this->expectException(CommandNotFoundException::class);
        $this->expectExceptionMessage('Command "unknown" is not defined.');

        $loader->get('unknown');
    }
}
