<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Document;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogRelease::class)]
final class ChangelogReleaseTest extends TestCase
{
    #[Test]
    public function constructorNormalizesEntriesAndExposesReleaseData(): void
    {
        $release = new ChangelogRelease('1.2.3', '2026-09-05', [
            'Added' => ['first', 'first', 'second'],
            'Unknown' => ['ignored'],
        ]);

        self::assertSame('1.2.3', $release->getVersion());
        self::assertSame('2026-09-05', $release->getDate());
        self::assertFalse($release->isUnreleased());
        self::assertTrue($release->hasEntries());
        self::assertSame(['first', 'second'], $release->getEntriesFor(ChangelogEntryType::Added));
        self::assertArrayNotHasKey('Unknown', $release->getEntries());
    }

    #[Test]
    public function emptyUnreleasedHasNoEntries(): void
    {
        $release = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);

        self::assertTrue($release->isUnreleased());
        self::assertFalse($release->hasEntries());
        self::assertNull($release->getDate());
    }

    #[Test]
    public function withEntryIgnoresEmptyValuesAndReturnsANormalizedCopy(): void
    {
        $original = new ChangelogRelease('1.0.0');

        self::assertSame($original, $original->withEntry(ChangelogEntryType::Fixed, '   '));

        $changed = $original
            ->withEntry(ChangelogEntryType::Fixed, ' fix ')
            ->withEntry(ChangelogEntryType::Fixed, 'fix');

        self::assertNotSame($original, $changed);
        self::assertSame(['fix'], $changed->getEntriesFor(ChangelogEntryType::Fixed));
        self::assertSame([], $original->getEntriesFor(ChangelogEntryType::Fixed));
    }

    #[Test]
    public function withEntriesAndWithDateReturnIndependentNormalizedCopies(): void
    {
        $original = new ChangelogRelease('1.0.0', '2026-01-01', ['Added' => ['old']]);
        $entriesChanged = $original->withEntries(['Security' => ['audit', 'audit']]);
        $dateChanged = $entriesChanged->withDate(null);

        self::assertSame(['old'], $original->getEntriesFor(ChangelogEntryType::Added));
        self::assertSame([], $entriesChanged->getEntriesFor(ChangelogEntryType::Added));
        self::assertSame(['audit'], $entriesChanged->getEntriesFor(ChangelogEntryType::Security));
        self::assertSame('2026-01-01', $entriesChanged->getDate());
        self::assertNull($dateChanged->getDate());
    }
}
