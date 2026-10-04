<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(Category::class)]
final class CategoryTest extends TestCase
{
    #[Test]
    #[TestWith([Category::Added, 'added', 'Added', VersionImpact::Minor])]
    #[TestWith([Category::Changed, 'changed', 'Changed', VersionImpact::Minor])]
    #[TestWith([Category::Deprecated, 'deprecated', 'Deprecated', VersionImpact::Minor])]
    #[TestWith([Category::Removed, 'removed', 'Removed', VersionImpact::Major])]
    #[TestWith([Category::Fixed, 'fixed', 'Fixed', VersionImpact::Patch])]
    #[TestWith([Category::Security, 'security', 'Security', VersionImpact::Patch])]
    public function casesExposeCanonicalMetadataAndImpact(
        Category $category,
        string $value,
        string $heading,
        VersionImpact $impact,
    ): void {
        self::assertSame($value, $category->value);
        self::assertSame($heading, $category->heading());
        self::assertSame($impact, $category->inferredImpact());
    }
}
