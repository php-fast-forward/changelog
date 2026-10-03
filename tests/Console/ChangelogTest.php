<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Console\Changelog;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

#[CoversClass(Changelog::class)]
final class ChangelogTest extends TestCase
{
    #[Test]
    public function applicationMetadataUsesOnlyInjectedPackageVersion(): void
    {
        $version = $this->createMock(PackageVersionResolverInterface::class);
        $version->expects(self::once())->method('resolve')->willReturn('9.8.7');
        $loader = $this->createMock(CommandLoaderInterface::class);
        $loader->expects(self::never())->method('get');
        $app = new Changelog($loader, $version);
        self::assertSame('Fast Forward Changelog', $app->getName());
        self::assertSame('9.8.7', $app->getVersion());
    }
}
