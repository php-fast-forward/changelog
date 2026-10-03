<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(VersionImpact::class)]
final class ChangesetTest extends TestCase
{
    #[Test]
    public function storesMetadataAndInfersImpactWhenVersionIsAbsent(): void
    {
        $changeset = new Changeset(
            'fixed-parser-a1b2c3d4.md',
            Category::Fixed,
            12,
            34,
            '@coisa',
            'Fix parser.',
        );

        self::assertSame('fixed-parser-a1b2c3d4.md', $changeset->id);
        self::assertSame(Category::Fixed, $changeset->category);
        self::assertSame(12, $changeset->issue);
        self::assertSame(34, $changeset->pullRequest);
        self::assertSame('@coisa', $changeset->author);
        self::assertSame('Fix parser.', $changeset->description);
        self::assertSame(VersionImpact::Patch, $changeset->type);
        self::assertSame(VersionImpact::Patch, $changeset->effectiveImpact());
    }

    #[Test]
    public function explicitVersionOverridesTheInferredImpact(): void
    {
        $changeset = new Changeset(
            'breaking-change-a1b2c3d4.md',
            Category::Changed,
            null,
            null,
            '@coisa',
            'Change the public contract.',
            VersionImpact::Major,
        );

        self::assertSame(VersionImpact::Major, $changeset->effectiveImpact());
    }

    #[Test]
    public function explicitTypeCanLowerTheCategoryInference(): void
    {
        $changeset = new Changeset(
            'removed-contract-a1b2c3d4.md',
            Category::Removed,
            null,
            42,
            '@coisa',
            'Remove the public contract.',
            VersionImpact::Patch,
        );

        self::assertSame(VersionImpact::Patch, $changeset->effectiveImpact());
    }
}
