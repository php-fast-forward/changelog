<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Container\ServiceProvider;

use FastForward\Changelog\Console\CommandLoader\ChangelogCommandLoader;
use FastForward\Changelog\Console\CommandLoader\LazyCommandFactoryInterface;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use FastForward\Changelog\Date\ReleaseDateValidatorInterface;
use FastForward\Changelog\Filesystem\PackagePathResolver;
use FastForward\Changelog\Git\ProcessFactory;
use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use FastForward\Clock\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

#[CoversClass(ChangelogServiceProvider::class)]
#[UsesClass(ChangelogCommandLoader::class)]
#[UsesClass(ComposerPackageVersionResolver::class)]
#[UsesClass(PackagePathResolver::class)]
#[UsesClass(ProcessFactory::class)]
final class ChangelogServiceProviderTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function factoriesExposeLazyAliasesAndCompositionFactories(): void
    {
        $provider = new ChangelogServiceProvider('/fixture/project', '1.2.3');
        $factories = $provider->getFactories();

        self::assertArrayHasKey(ComposerPackageVersionResolver::class, $factories);
        self::assertArrayHasKey(PackagePathResolver::class, $factories);
        self::assertArrayHasKey(CommandLoaderInterface::class, $factories);
        self::assertArrayHasKey(ReleaseDateValidatorInterface::class, $factories);
        self::assertCount(21, $factories);
        self::assertInstanceOf(ProcessFactory::class, $factories[ProcessFactory::class]());
        self::assertInstanceOf(SystemClock::class, $factories[SystemClock::class]());
        $versionResolver = $factories[ComposerPackageVersionResolver::class]();
        self::assertInstanceOf(ComposerPackageVersionResolver::class, $versionResolver);
        self::assertSame('1.2.3', $versionResolver->resolve());

        $container = $this->prophesize(ContainerInterface::class);
        $pathResolver = $factories[PackagePathResolver::class]($container->reveal());
        self::assertInstanceOf(PackagePathResolver::class, $pathResolver);
        self::assertSame('/fixture/project/CHANGELOG.md', $pathResolver->absolutePath('CHANGELOG.md'));

        $lazyFactory = $this->prophesize(LazyCommandFactoryInterface::class)->reveal();
        $container->get(LazyCommandFactoryInterface::class)->willReturn($lazyFactory)->shouldBeCalledOnce();
        self::assertInstanceOf(ChangelogCommandLoader::class, $factories[CommandLoaderInterface::class]($container->reveal()));
    }

    #[Test]
    public function extensionsAreEmpty(): void
    {
        self::assertSame([], new ChangelogServiceProvider('/fixture/project')->getExtensions());
    }
}
