<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version;

use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerPackageVersionResolver::class)]
final class ComposerPackageVersionResolverTest extends TestCase
{
    #[Test]
    #[TestWith(['1.2.3'])]
    #[TestWith([' dev-main '])]
    public function resolveReturnsTheInjectedComposerVersion(string $version): void
    {
        self::assertSame($version, new ComposerPackageVersionResolver($version)->resolve());
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith([" \t\n "])]
    public function resolveUsesTheDevelopmentVersionWhenComposerHasNoVersion(?string $version): void
    {
        self::assertSame('0.1.x-dev', new ComposerPackageVersionResolver($version)->resolve());
    }
}
