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

namespace FastForward\Changelog\Tests\Parser;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Parser\ChangelogParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(ChangelogParser::class)]
#[UsesClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
#[UsesClass(ChangelogEntryType::class)]
final class ChangelogParserTest extends TestCase
{
    #[Test]
    public function parseWillExtractReleaseSectionsAndEntries(): void
    {
        $document = (new ChangelogParser())->parse(<<<'MD'
            # Changelog

            All notable changes to this project will be documented in this file.

            The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
            and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

            ## [Unreleased]

            ### Added

            - Add release preparation workflow

            ### Fixed

            - Correct changelog checks

            ## [1.0.0] - 2026-04-01

            ### Added

            - Initial release
            MD);

        self::assertSame(ChangelogDocument::UNRELEASED_VERSION, $document->getUnreleased()->getVersion());
        self::assertSame(['Add release preparation workflow'], $document->getUnreleased()->getEntries()['Added']);
        self::assertSame('2026-04-01', $document->getRelease('1.0.0')?->getDate());
    }

    #[Test]
    public function parseWillReturnDefaultDocumentForEmptyContents(): void
    {
        $document = (new ChangelogParser())->parse("   \n\n");

        self::assertSame([ChangelogDocument::UNRELEASED_VERSION], array_map(
            static fn(ChangelogRelease $release): string => $release->getVersion(),
            $document->getReleases(),
        ));
    }

    #[Test]
    public function parseWillIgnoreUnsupportedLinesAndDeduplicateEntriesWithinASection(): void
    {
        $document = (new ChangelogParser())->parse(<<<'MD'
            ## [Unreleased]

            ### Added

            Intro line that should be ignored
            - Add sync command
            - Add sync command
            *

            ### Fixed

            - Repair coverage report
            -
            MD);

        self::assertSame(['Add sync command'], $document->getUnreleased()->getEntriesFor(ChangelogEntryType::Added));
        self::assertSame(['Repair coverage report'], $document->getUnreleased()->getEntriesFor(ChangelogEntryType::Fixed));
        self::assertSame([], $document->getUnreleased()->getEntriesFor(ChangelogEntryType::Security));
    }

    #[Test]
    public function extractEntriesWillReturnEmptyArrayWhenCategoryHeadingIsMissing(): void
    {
        $parser = new ChangelogParser();
        $reflectionMethod = new ReflectionMethod($parser, 'extractEntries');

        self::assertSame(
            [],
            $reflectionMethod->invoke($parser, "### Fixed\n\n- Repair release notes", ChangelogEntryType::Added),
        );
    }
}
