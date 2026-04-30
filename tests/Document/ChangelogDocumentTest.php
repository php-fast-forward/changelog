<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Tests\Document;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogDocument::class)]
#[CoversClass(ChangelogRelease::class)]
#[UsesClass(ChangelogEntryType::class)]
final class ChangelogDocumentTest extends TestCase
{
    #[Test]
    public function withReleaseWillKeepTheUnreleasedSectionAtTheTop(): void
    {
        $document = ChangelogDocument::create()
            ->withRelease((new ChangelogRelease('1.1.0', '2026-04-10'))->withEntry(
                ChangelogEntryType::Added,
                'Ship changelog automation',
            ))
            ->withRelease((new ChangelogRelease('1.0.0', '2026-04-01'))->withEntry(
                ChangelogEntryType::Fixed,
                'Stabilize command output',
            ));

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, '1.1.0', '1.0.0'],
            array_map(
                static fn(ChangelogRelease $release): string => $release->getVersion(),
                $document->getReleases(),
            ),
        );
    }

    #[Test]
    public function promoteUnreleasedWillMergeWithAnExistingPublishedVersion(): void
    {
        $document = new ChangelogDocument([
            (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))
                ->withEntry(ChangelogEntryType::Added, 'Add release command')
                ->withEntry(ChangelogEntryType::Fixed, 'Preserve release sections'),
            (new ChangelogRelease('1.2.0', '2026-04-01'))
                ->withEntry(ChangelogEntryType::Added, 'Existing release note'),
        ]);

        $promoted = $document->promoteUnreleased('1.2.0', '2026-04-19');
        $release = $promoted->getRelease('1.2.0');

        self::assertInstanceOf(ChangelogRelease::class, $release);
        self::assertSame('2026-04-19', $release->getDate());
        self::assertSame(
            ['Existing release note', 'Add release command'],
            $release->getEntriesFor(ChangelogEntryType::Added),
        );
        self::assertSame(['Preserve release sections'], $release->getEntriesFor(ChangelogEntryType::Fixed));
        self::assertFalse($promoted->getUnreleased()->hasEntries());
    }

    #[Test]
    public function documentAccessorsWillResolveExpectedReleaseVariants(): void
    {
        $document = new ChangelogDocument([new ChangelogRelease('1.2.0', '2026-04-19')]);

        self::assertSame(ChangelogDocument::UNRELEASED_VERSION, $document->getUnreleased()->getVersion());
        self::assertNull($document->getRelease('9.9.9'));
        self::assertSame('1.2.0', $document->getLatestPublishedRelease()?->getVersion());
    }

    #[Test]
    public function getLatestPublishedReleaseWillReturnNullWhenOnlyUnreleasedExists(): void
    {
        self::assertNull(ChangelogDocument::create()->getLatestPublishedRelease());
    }

    #[Test]
    public function withReleaseWillReplaceExistingVersionAndInsertUnreleasedAtTheTop(): void
    {
        $existing = new ChangelogRelease('1.2.0', '2026-04-01');
        $replacement = (new ChangelogRelease('1.2.0', '2026-04-19'))
            ->withEntry(ChangelogEntryType::Added, 'Updated note');
        $document = (new ChangelogDocument([$existing]))
            ->withRelease(new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))
            ->withRelease($replacement);

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, '1.2.0'],
            array_map(
                static fn(ChangelogRelease $release): string => $release->getVersion(),
                $document->getReleases(),
            ),
        );
        self::assertSame(['Updated note'], $document->getRelease('1.2.0')?->getEntriesFor(ChangelogEntryType::Added));
    }

    #[Test]
    public function withReleaseWillPreservePublishedOrderingWhenBackfillingOlderVersions(): void
    {
        $document = ChangelogDocument::create()
            ->withRelease(new ChangelogRelease('2.0.0', '2026-04-20'))
            ->withRelease(new ChangelogRelease('1.5.0', '2026-03-10'));

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, '2.0.0', '1.5.0'],
            array_map(
                static fn(ChangelogRelease $release): string => $release->getVersion(),
                $document->getReleases(),
            ),
        );
        self::assertSame('2.0.0', $document->getLatestPublishedRelease()?->getVersion());
    }
}
