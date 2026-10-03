<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Entry;

use FastForward\Changelog\Entry\ChangelogEntryType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogEntryType::class)]
final class ChangelogEntryTypeTest extends TestCase
{
    #[Test]
    public function casesExposeKeepAChangelogCategoryValues(): void
    {
        self::assertSame(
            ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'],
            array_map(
                static fn(ChangelogEntryType $type): string => $type->value,
                ChangelogEntryType::cases(),
            ),
        );
    }
}
