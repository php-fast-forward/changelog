<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset\Factory;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Factory\ChangesetFactory;
use FastForward\Changelog\Changeset\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangesetFactory::class)]
#[UsesClass(Changeset::class)]
final class ChangesetFactoryTest extends TestCase
{
    #[Test]
    public function createForwardsEveryValidatedValue(): void
    {
        $changeset = new ChangesetFactory()->create(
            'api-contract-a1b2c3d4.md',
            Category::Changed,
            10,
            20,
            '@coisa',
            'Explain the change.',
            VersionImpact::Major,
        );

        self::assertSame('api-contract-a1b2c3d4.md', $changeset->id);
        self::assertSame(Category::Changed, $changeset->category);
        self::assertSame(10, $changeset->issue);
        self::assertSame(20, $changeset->pullRequest);
        self::assertSame('@coisa', $changeset->author);
        self::assertSame('Explain the change.', $changeset->description);
        self::assertSame(VersionImpact::Major, $changeset->type);
    }
}
