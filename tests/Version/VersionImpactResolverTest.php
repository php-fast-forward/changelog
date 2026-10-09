<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Version\VersionImpact;
use FastForward\Changelog\Version\VersionImpactResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionImpactResolver::class)]
#[UsesClass(Category::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(VersionImpact::class)]
final class VersionImpactResolverTest extends TestCase
{
    #[Test]
    public function resolveReturnsNullForAnEmptyCollection(): void
    {
        self::assertNull(new VersionImpactResolver()->resolve([]));
    }

    #[Test]
    public function resolveKeepsEveryFragmentAndSelectsTheGreatestEffectiveImpact(): void
    {
        $changesets = [
            new Changeset('fix.md', Category::Fixed, null, 77, '@coisa', 'Fix.'),
            new Changeset('feature.md', Category::Added, null, 77, '@coisa', 'Feature.'),
            new Changeset(
                'breaking.md',
                Category::Changed,
                null,
                77,
                '@coisa',
                'Breaking.',
                VersionImpact::Major,
            ),
        ];
        $resolver = new VersionImpactResolver();

        self::assertSame(VersionImpact::Major, $resolver->resolve($changesets));
        self::assertSame(VersionImpact::Major, $resolver->resolve(array_reverse($changesets)));
    }
    #[Test]
    public function explicitLowerImpactRemainsLowerWhenAggregated(): void
    {
        $changesets = [
            new Changeset('removed.md', Category::Removed, null, null, null, 'Internal removal.', VersionImpact::Patch),
            new Changeset('deprecated.md', Category::Deprecated, null, null, null, 'Deprecation.'),
        ];

        self::assertSame(VersionImpact::Minor, new VersionImpactResolver()->resolve($changesets));
    }

}
