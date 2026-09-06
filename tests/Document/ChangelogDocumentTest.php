<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Document;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
final class ChangelogDocumentTest extends TestCase
{
    #[Test]
    public function accessorsReturnKnownReleaseVariants(): void
    {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $published = new ChangelogRelease('1.2.0');
        $references = ['[1.2.0]: https://example.com/releases/tag/v1.2.0'];
        $document = new ChangelogDocument([$unreleased, $published], $references);

        self::assertSame([$unreleased, $published], $document->getReleases());
        self::assertSame($references, $document->getReferences());
        self::assertSame($unreleased, $document->getUnreleased());
        self::assertSame($published, $document->getRelease('1.2.0'));
        self::assertNull($document->getRelease('9.9.9'));
        self::assertSame($published, $document->getLatestPublishedRelease());
        self::assertNull((new ChangelogDocument([$unreleased]))->getLatestPublishedRelease());
    }

    #[Test]
    public function getUnreleasedRejectsAnInvalidDocument(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A changelog document MUST contain an Unreleased section.');

        (new ChangelogDocument([new ChangelogRelease('1.0.0')]))->getUnreleased();
    }

    #[Test]
    public function withReleaseReplacesExistingVersionsWithoutMutatingTheSource(): void
    {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $existing = new ChangelogRelease('1.0.0', '2026-01-01');
        $replacement = new ChangelogRelease('1.0.0', '2026-02-01');
        $source = new ChangelogDocument([$unreleased, $existing]);
        $changed = $source->withRelease($replacement);

        self::assertSame($existing, $source->getRelease('1.0.0'));
        self::assertNotSame($replacement, $changed->getRelease('1.0.0'));
        self::assertSame('1.0.0', $changed->getRelease('1.0.0')?->getVersion());
        self::assertSame('2026-02-01', $changed->getRelease('1.0.0')?->getDate());
    }

    #[Test]
    public function mutationsPreserveReferenceDefinitions(): void
    {
        $references = ['[unreleased]: https://example.com/compare/v1.0.0...HEAD'];
        $unreleased = (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))
            ->withEntry(ChangelogEntryType::Added, 'entry');
        $document = new ChangelogDocument([$unreleased], $references);

        $withRelease = $document->withRelease(new ChangelogRelease('1.0.0'));
        $promoted = $document->promoteUnreleased(
            new ChangelogRelease('1.0.0', '2026-09-05', $unreleased->getEntries()),
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
        );

        self::assertSame($references, $withRelease->getReferences());
        self::assertSame($references, $promoted->getReferences());
    }

    #[Test]
    public function prefixedAndUnprefixedLabelsShareIdentityWhilePreservingThePublishedLabel(): void
    {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $existing = (new ChangelogRelease('v1.2.0', '2026-01-01'))
            ->withEntry(ChangelogEntryType::Added, 'old');
        $target = (new ChangelogRelease('1.2.0', '2026-09-05'))
            ->withEntry(ChangelogEntryType::Fixed, 'new');
        $document = new ChangelogDocument([$unreleased, $existing]);

        self::assertSame($existing, $document->getRelease('1.2.0'));

        $changed = $document->withRelease($target);
        $release = $changed->getRelease('V1.2.0');

        self::assertSame('v1.2.0', $release?->getVersion());
        self::assertSame('2026-09-05', $release?->getDate());
        self::assertSame(['new'], $release?->getEntriesFor(ChangelogEntryType::Fixed));
        self::assertSame([], $release?->getEntriesFor(ChangelogEntryType::Added));
    }

    #[Test]
    public function withReleaseAddsAndNormalizesTheUnreleasedSection(): void
    {
        $first = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $second = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $published = new ChangelogRelease('1.0.0');

        $added = (new ChangelogDocument([$published]))->withRelease($first);
        $normalized = (new ChangelogDocument([$published, $first, $second]))
            ->withRelease(new ChangelogRelease('0.9.0'));

        self::assertSame($first, $added->getReleases()[0]);
        self::assertSame([$first, $published, $normalized->getRelease('0.9.0')], $normalized->getReleases());
    }

    #[Test]
    public function withReleaseOrdersSemanticVersionsAndPrefixesNewestFirst(): void
    {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $document = new ChangelogDocument([$unreleased, new ChangelogRelease('v2.0.0')]);

        $changed = $document
            ->withRelease(new ChangelogRelease('V3.0.0'))
            ->withRelease(new ChangelogRelease('1.0.0'));

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, 'V3.0.0', 'v2.0.0', '1.0.0'],
            array_map(static fn(ChangelogRelease $release): string => $release->getVersion(), $changed->getReleases()),
        );
    }

    #[Test]
    public function withReleaseFallsBackToDatesAndThenAppendOrder(): void
    {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $dated = new ChangelogDocument([$unreleased, new ChangelogRelease('stable', '2026-01-01')]);
        $dated = $dated->withRelease(new ChangelogRelease('next', '2026-02-01'));
        $undated = $dated->withRelease(new ChangelogRelease('custom'));

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, 'next', 'stable', 'custom'],
            array_map(static fn(ChangelogRelease $release): string => $release->getVersion(), $undated->getReleases()),
        );
    }

    #[Test]
    #[TestWith(['2.0.0', '1.0.0'])]
    #[TestWith(['1.2.0', '1.1.9'])]
    #[TestWith(['1.0.2', '1.0.1'])]
    #[TestWith(['1.0.0', '1.0.0-rc.1'])]
    #[TestWith(['1.0.0-beta.1', '1.0.0-beta'])]
    #[TestWith(['1.0.0-beta.1', '1.0.0-beta'])]
    #[TestWith(['1.0.0-alpha.10', '1.0.0-alpha.2'])]
    #[TestWith(['1.0.0-alpha.beta', '1.0.0-alpha.1'])]
    #[TestWith(['1.0.0-beta', '1.0.0-alpha'])]
    public function withReleaseImplementsSemanticVersionPrereleasePrecedence(
        string $higher,
        string $lower,
    ): void {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $document = new ChangelogDocument([$unreleased, new ChangelogRelease($lower)]);

        $changed = $document->withRelease(new ChangelogRelease($higher));

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, $higher, $lower],
            array_map(static fn(ChangelogRelease $release): string => $release->getVersion(), $changed->getReleases()),
        );
    }

    #[Test]
    #[TestWith(['1.0.0-alpha', '1.0.0-alpha.1'])]
    #[TestWith(['1.0.0-alpha.1', '1.0.0-alpha.beta'])]
    #[TestWith(['1.0.0-rc.1', '1.0.0'])]
    public function withReleaseKeepsHigherSemanticVersionBeforeLowerTarget(
        string $lower,
        string $higher,
    ): void {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $document = new ChangelogDocument([$unreleased, new ChangelogRelease($higher)]);

        $changed = $document->withRelease(new ChangelogRelease($lower));

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, $higher, $lower],
            array_map(static fn(ChangelogRelease $release): string => $release->getVersion(), $changed->getReleases()),
        );
    }

    #[Test]
    public function equalSemverPrecedenceAndInvalidPrereleaseUseExistingFallbacks(): void
    {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $equalPrecedence = new ChangelogDocument([
            $unreleased,
            new ChangelogRelease('1.0.0+build.1'),
        ]);
        $equalPrecedence = $equalPrecedence->withRelease(new ChangelogRelease('1.0.0+build.2'));

        self::assertSame('1.0.0+build.1', $equalPrecedence->getReleases()[1]->getVersion());
        self::assertSame('1.0.0+build.2', $equalPrecedence->getReleases()[2]->getVersion());

        $equalPrerelease = new ChangelogDocument([
            $unreleased,
            new ChangelogRelease('1.0.0-alpha+build.1'),
        ]);
        $equalPrerelease = $equalPrerelease->withRelease(new ChangelogRelease('1.0.0-alpha+build.2'));

        self::assertSame('1.0.0-alpha+build.1', $equalPrerelease->getReleases()[1]->getVersion());
        self::assertSame('1.0.0-alpha+build.2', $equalPrerelease->getReleases()[2]->getVersion());

        $invalidPrerelease = new ChangelogDocument([
            $unreleased,
            new ChangelogRelease('1.0.0-alpha.1', '2026-01-01'),
        ]);
        $invalidPrerelease = $invalidPrerelease->withRelease(
            new ChangelogRelease('1.0.0-alpha.01', '2026-02-01'),
        );

        self::assertSame('1.0.0-alpha.01', $invalidPrerelease->getReleases()[1]->getVersion());
    }

    #[Test]
    public function withPublishedReleaseRejectsAInvalidDocumentWithoutUnreleased(): void
    {
        $this->expectException(LogicException::class);

        (new ChangelogDocument([new ChangelogRelease('1.0.0')]))
            ->withRelease(new ChangelogRelease('2.0.0'));
    }

    #[Test]
    public function promoteUnreleasedCreatesAnOrderedPublishedRelease(): void
    {
        $unreleased = (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))
            ->withEntry(ChangelogEntryType::Fixed, 'fix');
        $empty = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $promoted = new ChangelogRelease('1.5.0', '2026-02-01', $unreleased->getEntries());
        $document = new ChangelogDocument([
            $unreleased,
            new ChangelogRelease('2.0.0', '2026-03-01'),
            new ChangelogRelease('1.0.0', '2026-01-01'),
        ]);

        $changed = $document->promoteUnreleased($promoted, $empty);

        self::assertSame(
            [ChangelogDocument::UNRELEASED_VERSION, '2.0.0', '1.5.0', '1.0.0'],
            array_map(static fn(ChangelogRelease $release): string => $release->getVersion(), $changed->getReleases()),
        );
        self::assertFalse($changed->getUnreleased()->hasEntries());
    }

    #[Test]
    public function promoteUnreleasedMergesAnExistingVersionWithoutDuplicates(): void
    {
        $unreleased = (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))
            ->withEntry(ChangelogEntryType::Added, 'shared')
            ->withEntry(ChangelogEntryType::Fixed, 'new fix');
        $existing = (new ChangelogRelease('1.0.0'))
            ->withEntry(ChangelogEntryType::Added, 'existing')
            ->withEntry(ChangelogEntryType::Added, 'shared');
        $promoted = new ChangelogRelease('1.0.0', '2026-09-05', $unreleased->getEntries());

        $changed = (new ChangelogDocument([$unreleased, $existing]))->promoteUnreleased(
            $promoted,
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
        );

        self::assertSame(['existing', 'shared'], $changed->getRelease('1.0.0')?->getEntriesFor(ChangelogEntryType::Added));
        self::assertSame(['new fix'], $changed->getRelease('1.0.0')?->getEntriesFor(ChangelogEntryType::Fixed));
        self::assertSame('2026-09-05', $changed->getRelease('1.0.0')?->getDate());
    }

    #[Test]
    public function promoteUnreleasedPreservesTheExistingPrefixedLabel(): void
    {
        $unreleased = (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))
            ->withEntry(ChangelogEntryType::Fixed, 'new');
        $existing = (new ChangelogRelease('V1.2.0', '2026-01-01'))
            ->withEntry(ChangelogEntryType::Added, 'old');
        $document = new ChangelogDocument([$unreleased, $existing]);

        $changed = $document->promoteUnreleased(
            new ChangelogRelease('1.2.0', '2026-09-05', $unreleased->getEntries()),
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
        );
        $release = $changed->getRelease('v1.2.0');

        self::assertSame('V1.2.0', $release?->getVersion());
        self::assertSame('2026-09-05', $release?->getDate());
        self::assertSame(['old'], $release?->getEntriesFor(ChangelogEntryType::Added));
        self::assertSame(['new'], $release?->getEntriesFor(ChangelogEntryType::Fixed));
        self::assertCount(2, $changed->getReleases());
    }
}
