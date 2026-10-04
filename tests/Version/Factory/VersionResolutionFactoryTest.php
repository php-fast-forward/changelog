<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version\Factory;

use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\Version\Factory\VersionResolutionFactory;
use FastForward\Changelog\Version\VersionResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionResolutionFactory::class)]
#[UsesClass(VersionResolution::class)]
final class VersionResolutionFactoryTest extends TestCase
{
    #[Test]
    public function resolvedCreatesACompleteSuccessfulResult(): void
    {
        $result = new VersionResolutionFactory()->resolved('2.0.0', VersionImpact::Major);

        self::assertSame('2.0.0', $result->nextVersion);
        self::assertSame(VersionImpact::Major, $result->impact);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function invalidCreatesAnErrorOnlyResult(): void
    {
        $result = new VersionResolutionFactory()->invalid(['first', 'second']);

        self::assertNull($result->nextVersion);
        self::assertNull($result->impact);
        self::assertSame(['first', 'second'], $result->errors);
    }
}
