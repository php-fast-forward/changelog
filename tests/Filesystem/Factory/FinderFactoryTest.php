<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Filesystem\Factory;

use FastForward\Changelog\Filesystem\Factory\FinderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

#[CoversClass(FinderFactory::class)]
final class FinderFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsAFreshUnconfiguredFinderWithoutScanning(): void
    {
        $factory = new FinderFactory();

        $first = $factory->create();
        $second = $factory->create();

        self::assertInstanceOf(Finder::class, $first);
        self::assertInstanceOf(Finder::class, $second);
        self::assertNotSame($first, $second);
    }
}
