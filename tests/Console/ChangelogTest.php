<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Console\Changelog;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

#[CoversClass(Changelog::class)]
final class ChangelogTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function constructorSetsVersionAndLazyCommandLoaderWithoutLoadingCommands(): void
    {
        $loader = $this->prophesize(CommandLoaderInterface::class);
        $versionResolver = $this->prophesize(PackageVersionResolverInterface::class);
        $versionResolver->resolve()->willReturn('1.2.3')->shouldBeCalledOnce();
        $loader->getNames()->shouldNotBeCalled();
        $loader->get('changelog:entry')->shouldNotBeCalled();

        $application = new Changelog($loader->reveal(), $versionResolver->reveal());

        self::assertSame('Fast Forward Changelog', $application->getName());
        self::assertSame('1.2.3', $application->getVersion());
    }
}
