<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Entry;

use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Entry\ChangelogEntryTypes;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogEntryTypes::class)]
final class ChangelogEntryTypesTest extends TestCase
{
    #[Test]
    public function orderedReturnsKeepAChangelogSectionOrder(): void
    {
        self::assertSame(
            [
                ChangelogEntryType::Added,
                ChangelogEntryType::Changed,
                ChangelogEntryType::Deprecated,
                ChangelogEntryType::Removed,
                ChangelogEntryType::Fixed,
                ChangelogEntryType::Security,
            ],
            (new ChangelogEntryTypes())->ordered(),
        );
    }

    #[Test]
    #[TestWith([' added ', ChangelogEntryType::Added])]
    #[TestWith(['CHANGED', ChangelogEntryType::Changed])]
    #[TestWith(['deprecated', ChangelogEntryType::Deprecated])]
    #[TestWith(['removed', ChangelogEntryType::Removed])]
    #[TestWith(['fixed', ChangelogEntryType::Fixed])]
    #[TestWith(['security', ChangelogEntryType::Security])]
    public function fromInputNormalizesEverySupportedValue(string $input, ChangelogEntryType $expected): void
    {
        self::assertSame($expected, (new ChangelogEntryTypes())->fromInput($input));
    }

    #[Test]
    public function fromInputRejectsUnsupportedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported changelog type "unknown".');

        (new ChangelogEntryTypes())->fromInput('unknown');
    }
}
