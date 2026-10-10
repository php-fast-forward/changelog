<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version;

use FastForward\Changelog\Version\VersionImpact;
use FastForward\Changelog\Version\VersionResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionResolution::class)]
final class VersionResolutionTest extends TestCase
{
    #[Test]
    public function resolutionIsValidOnlyWhenEverySuccessFieldIsConsistent(): void
    {
        $valid = new VersionResolution('1.2.3', VersionImpact::Minor, []);

        self::assertSame('1.2.3', $valid->nextVersion);
        self::assertSame(VersionImpact::Minor, $valid->impact);
        self::assertSame([], $valid->errors);
        self::assertTrue($valid->isValid());
        self::assertFalse(new VersionResolution(null, VersionImpact::Minor, [])->isValid());
        self::assertFalse(new VersionResolution('1.2.3', null, [])->isValid());
        self::assertFalse(new VersionResolution('1.2.3', VersionImpact::Minor, ['error'])->isValid());
    }
}
