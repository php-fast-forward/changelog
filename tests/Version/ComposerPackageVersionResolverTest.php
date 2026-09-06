<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version;

use FastForward\Changelog\Version\ComposerPackageVersionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComposerPackageVersionResolver::class)]
final class ComposerPackageVersionResolverTest extends TestCase
{
    #[Test]
    public function resolveReturnsTheInjectedComposerVersion(): void
    {
        self::assertSame('1.2.3', (new ComposerPackageVersionResolver('1.2.3'))->resolve());
    }

    #[Test]
    public function resolveUsesTheDevelopmentVersionWhenComposerHasNoVersion(): void
    {
        self::assertSame('0.1.x-dev', (new ComposerPackageVersionResolver(null))->resolve());
    }
}
