<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Date\Factory;

use FastForward\Changelog\Date\Factory\TimezoneFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimezoneFactory::class)]
final class TimezoneFactoryTest extends TestCase
{
    /** The date value is independent from the host timezone and clock. */
    public function testUtcIsExplicit(): void
    {
        self::assertSame('UTC', new TimezoneFactory()->create()->getName());
    }
}
