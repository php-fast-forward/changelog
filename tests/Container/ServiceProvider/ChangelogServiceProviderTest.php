<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Container\ServiceProvider;

use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\LazyCommandFactoryInterface;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Date\ReleaseDateValidatorInterface;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

#[CoversClass(ChangelogServiceProvider::class)]
#[UsesClass(ChangelogCommandLoader::class)]
#[UsesClass(ComposerPackageVersionResolver::class)]
#[UsesClass(PackagePathResolver::class)]
final class ChangelogServiceProviderTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function factoriesExposeLazyAliasesAndCompositionFactories(): void
    {
        $provider = new ChangelogServiceProvider('1.2.3');
        $factories = $provider->getFactories();

        self::assertArrayHasKey(ComposerPackageVersionResolver::class, $factories);
        self::assertArrayHasKey(PackagePathResolver::class, $factories);
        self::assertArrayHasKey(CommandLoaderInterface::class, $factories);
        self::assertArrayHasKey(ReleaseDateValidatorInterface::class, $factories);
        self::assertCount(19, $factories);
        $versionResolver = $factories[ComposerPackageVersionResolver::class]();
        self::assertInstanceOf(ComposerPackageVersionResolver::class, $versionResolver);
        self::assertSame('1.2.3', $versionResolver->resolve());

        $container = $this->prophesize(ContainerInterface::class);
        self::assertInstanceOf(PackagePathResolver::class, $factories[PackagePathResolver::class]($container->reveal()));

        $lazyFactory = $this->prophesize(LazyCommandFactoryInterface::class)->reveal();
        $container->get(LazyCommandFactoryInterface::class)->willReturn($lazyFactory)->shouldBeCalledOnce();
        self::assertInstanceOf(ChangelogCommandLoader::class, $factories[CommandLoaderInterface::class]($container->reveal()));
    }

    #[Test]
    public function extensionsAreEmpty(): void
    {
        self::assertSame([], (new ChangelogServiceProvider())->getExtensions());
    }
}
