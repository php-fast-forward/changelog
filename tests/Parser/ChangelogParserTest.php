<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Parser;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogDocumentFactoryInterface;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Document\ChangelogReleaseFactoryInterface;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Entry\ChangelogEntryTypesInterface;
use FastForward\Changelog\Parser\ChangelogParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(ChangelogParser::class)]
#[UsesClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
final class ChangelogParserTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    #[TestWith([''])]
    #[TestWith([" \n "])]
    #[TestWith(['# Changelog without release headings'])]
    public function parseReturnsTheFactoryDefaultWhenNoReleaseExists(string $contents): void
    {
        $default = new ChangelogDocument([new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION)]);
        $documentFactory = $this->prophesize(ChangelogDocumentFactoryInterface::class);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $entryTypes = $this->prophesize(ChangelogEntryTypesInterface::class);
        $documentFactory->create()->willReturn($default)->shouldBeCalledOnce();

        self::assertSame($default, new ChangelogParser(
            $documentFactory->reveal(),
            $releaseFactory->reveal(),
            $entryTypes->reveal(),
        )->parse($contents));
    }

    #[Test]
    public function parsePreservesSuffixedAndWhitespaceHeadingsBetweenReleases(): void
    {
        $documentFactory = $this->prophesize(ChangelogDocumentFactoryInterface::class);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $entryTypes = $this->prophesize(ChangelogEntryTypesInterface::class);
        $entryTypes->ordered()->willReturn([ChangelogEntryType::Fixed]);
        $first = new ChangelogRelease('2.0.0', null, ['Fixed' => ['first']]);
        $yanked = new ChangelogRelease('1.0.0', '2026-01-01', ['Fixed' => ['retained']], '[YANKED]');
        $last = new ChangelogRelease('0.1.0', null, ['Fixed' => ['last']]);
        $releaseFactory->create('2.0.0', null, ['Fixed' => ['first']])->willReturn($first)->shouldBeCalledOnce();
        $releaseFactory->create('1.0.0', '2026-01-01', ['Fixed' => ['retained']], '[YANKED]')->willReturn($yanked)->shouldBeCalledOnce();
        $releaseFactory->create('0.1.0', null, ['Fixed' => ['last']])->willReturn($last)->shouldBeCalledOnce();
        $expected = new ChangelogDocument([$first, $yanked, $last]);
        $documentFactory->create([$first, $yanked, $last], [])->willReturn($expected)->shouldBeCalledOnce();
        $parser = new ChangelogParser($documentFactory->reveal(), $releaseFactory->reveal(), $entryTypes->reveal());
        self::assertSame($expected, $parser->parse("## [2.0.0] \t\n### Fixed\n- first\n## [1.0.0] - 2026-01-01 [YANKED] \t\n### Fixed\n- retained\n## [0.1.0]  \r\n### Fixed\n- last\n"));
        self::assertSame('[YANKED]', $expected->getRelease('1.0.0')->getSuffix());
        self::assertSame(['retained'], $expected->getRelease('1.0.0')->getEntriesFor(ChangelogEntryType::Fixed));
    }

    #[Test]
    public function parseBuildsDatedAndUndatedReleaseValues(): void
    {
        $documentFactory = $this->prophesize(ChangelogDocumentFactoryInterface::class);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $entryTypes = $this->prophesize(ChangelogEntryTypesInterface::class);
        $entryTypes->ordered()->willReturn([ChangelogEntryType::Added, ChangelogEntryType::Fixed]);
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION, null, ['Added' => ['new']]);
        $published = new ChangelogRelease('1.0.0', '2026-09-05', ['Fixed' => ['fix']]);
        $releaseFactory->create(
            ChangelogDocument::UNRELEASED_VERSION,
            null,
            ['Added' => ['new'], 'Fixed' => []],
        )->willReturn($unreleased)->shouldBeCalledOnce();
        $releaseFactory->create(
            '1.0.0',
            '2026-09-05',
            ['Added' => [], 'Fixed' => ['fix']],
        )->willReturn($published)->shouldBeCalledOnce();
        $expected = new ChangelogDocument([$unreleased, $published]);
        $documentFactory->create([$unreleased, $published], [])->willReturn($expected)->shouldBeCalledOnce();
        $parser = new ChangelogParser($documentFactory->reveal(), $releaseFactory->reveal(), $entryTypes->reveal());

        $actual = $parser->parse(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Added

            - new

            ## [1.0.0] - 2026-09-05

            ### Fixed

            - fix
            MARKDOWN);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function parseIgnoresNoiseEmptyBulletsAndDuplicateEntries(): void
    {
        $documentFactory = $this->prophesize(ChangelogDocumentFactoryInterface::class);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $entryTypes = $this->prophesize(ChangelogEntryTypesInterface::class);
        $entryTypes->ordered()->willReturn([ChangelogEntryType::Added]);
        $release = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION, null, ['Added' => ['entry']]);
        $releaseFactory->create(
            ChangelogDocument::UNRELEASED_VERSION,
            null,
            ['Added' => ['entry']],
        )->willReturn($release)->shouldBeCalledOnce();
        $expected = new ChangelogDocument([$release]);
        $documentFactory->create([$release], [])->willReturn($expected)->shouldBeCalledOnce();
        $parser = new ChangelogParser($documentFactory->reveal(), $releaseFactory->reveal(), $entryTypes->reveal());

        self::assertSame($expected, $parser->parse(<<<'MARKDOWN'
            ## [Unreleased]

            ### Added

            prose is ignored
            -
            -
            - entry
            - entry
            MARKDOWN));
    }

    #[Test]
    public function parseIgnoresWhitespaceOnlyBulletsAndTheirContinuation(): void
    {
        $documentFactory = $this->prophesize(ChangelogDocumentFactoryInterface::class);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $entryTypes = $this->prophesize(ChangelogEntryTypesInterface::class);
        $entryTypes->ordered()->willReturn([ChangelogEntryType::Added]);
        $release = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION, null, ['Added' => ['first', 'last']]);
        $releaseFactory->create(ChangelogDocument::UNRELEASED_VERSION, null, ['Added' => ['first', 'last']])
            ->willReturn($release)->shouldBeCalledOnce();
        $expected = new ChangelogDocument([$release]);
        $documentFactory->create([$release], [])->willReturn($expected)->shouldBeCalledOnce();
        $parser = new ChangelogParser($documentFactory->reveal(), $releaseFactory->reveal(), $entryTypes->reveal());

        self::assertSame($expected, $parser->parse("## [Unreleased]\n\n### Added\n\n- first\n- \t \nprose\n  continuation without an entry\n- last\n"));
    }

    #[Test]
    public function parsePreservesCrLfMultilineEntriesNestedBulletsAndReferences(): void
    {
        $documentFactory = $this->prophesize(ChangelogDocumentFactoryInterface::class);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $entryTypes = $this->prophesize(ChangelogEntryTypesInterface::class);
        $entryTypes->ordered()->willReturn([ChangelogEntryType::Added]);
        $firstEntry = "First line\n  continuation\n  - nested bullet\n\n    indented paragraph";
        $release = new ChangelogRelease(
            ChangelogDocument::UNRELEASED_VERSION,
            null,
            ['Added' => [$firstEntry, 'Second entry']],
        );
        $releaseFactory->create(
            ChangelogDocument::UNRELEASED_VERSION,
            null,
            ['Added' => [$firstEntry, 'Second entry']],
        )->willReturn($release)->shouldBeCalledOnce();
        $references = [
            '[unreleased]: https://example.com/compare/v1.0.0...HEAD',
            '[1.0.0]: https://example.com/releases/tag/v1.0.0',
        ];
        $expected = new ChangelogDocument([$release], $references);
        $documentFactory->create([$release], $references)->willReturn($expected)->shouldBeCalledOnce();
        $parser = new ChangelogParser($documentFactory->reveal(), $releaseFactory->reveal(), $entryTypes->reveal());
        $contents = "## [Unreleased]\r\n\r\n### Added\r\n\r\n"
            . "- First line\r\n  continuation\r\n  - nested bullet\r\n\r\n    indented paragraph\r\n"
            . "- Second entry\r\n\r\n"
            . "[unreleased]: https://example.com/compare/v1.0.0...HEAD\r\n"
            . "[1.0.0]: https://example.com/releases/tag/v1.0.0\r\n";

        $document = $parser->parse($contents);

        self::assertSame($expected, $document);
        self::assertSame($references, $document->getReferences());
        self::assertSame([$firstEntry, 'Second entry'], $document->getUnreleased()->getEntriesFor(ChangelogEntryType::Added));
    }
}
