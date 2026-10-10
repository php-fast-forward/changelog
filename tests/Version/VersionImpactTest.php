<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version;

use FastForward\Changelog\Version\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionImpact::class)]
final class VersionImpactTest extends TestCase
{
    #[Test]
    #[TestWith([VersionImpact::Patch, 1])]
    #[TestWith([VersionImpact::Minor, 2])]
    #[TestWith([VersionImpact::Major, 3])]
    public function weightDefinesMonotonicPrecedence(VersionImpact $impact, int $weight): void
    {
        self::assertSame($weight, $impact->weight());
    }

    #[Test]
    #[TestWith([VersionImpact::Patch, VersionImpact::Minor, VersionImpact::Minor])]
    #[TestWith([VersionImpact::Minor, VersionImpact::Patch, VersionImpact::Minor])]
    #[TestWith([VersionImpact::Major, VersionImpact::Major, VersionImpact::Major])]
    public function elevateNeverLowersImpact(
        VersionImpact $current,
        VersionImpact $candidate,
        VersionImpact $expected,
    ): void {
        self::assertSame($expected, $current->elevate($candidate));
    }
}
