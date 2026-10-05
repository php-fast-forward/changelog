<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Tests\Release;

use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseHistoryConsolidator;
use FastForward\Changelog\Template\TemplateInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseHistoryConsolidator::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(\FastForward\Changelog\Changeset\Category::class)]
final class ReleaseHistoryConsolidatorTest extends TestCase
{
    /** A real version consumes pending notes, merges common categories and preserves published sections verbatim. */
    public function testPromotesPendingDescriptionsAndMergesCategoriesOnce(): void
    {
        $old = new HistoryRelease('1.0.0', body: "Old notes\r\n", heading: "## [1.0.0]\r\n");
        $document = new HistoryDocument([$old, new HistoryRelease('unreleased', body: "\n### Added\n\n- Bootstrap.\n\n### Fixed\n\n- Preserve  two spaces.\n")], "Introduction\n\n", "[ref]: https://example.test\n");
        $result = $this->service()->promote($document, '1.1.0', '2026-10-05', "### Changed\n\n- New behavior.\n\n### Fixed\n\n- New fix.\n", $this->template());
        self::assertNull($result->getRelease('unreleased'));
        self::assertSame([$result->getRelease('1.1.0'), $old], $result->getReleases());
        self::assertSame("### Added\n\n- Bootstrap.\n\n### Changed\n\n- New behavior.\n\n### Fixed\n\n- Preserve  two spaces.\n- New fix.\n", $result->getRelease('1.1.0')->getBody());
        self::assertSame('2026-10-05', $result->getRelease('1.1.0')->getDate());
        self::assertSame($document->getPrefix(), $result->getPrefix());
        self::assertSame($document->getReferences(), $result->getReferences());
        self::assertNotNull($document->getRelease('unreleased'));
    }

    /** Empty or absent legacy sections never change the already rendered fragment bytes. */
    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(["\r\n \t\n"])]
    public function testNoPendingContentRetainsRenderedBytes(?string $legacy): void
    {
        $document = new HistoryDocument(null === $legacy ? [] : [new HistoryRelease('unreleased', body: $legacy)]);
        $notes = "### Fixed\n\n- Exact  bytes\n";
        $result = $this->service()->promote($document, '0.0.1', null, $notes, $this->template());
        self::assertCount(1, $result->getReleases());
        self::assertSame($notes, $result->getRelease('0.0.1')->getBody());
    }

    /** Free prose, unknown headings, nested bullets and both fence styles are data rather than category boundaries. */
    #[TestWith(['```'])]
    #[TestWith(['~~~'])]
    public function testPreservesRichPendingMarkdown(string $fence): void
    {
        $legacy = "Legacy  prose.\r\n\r\n### Fixed\r\n\r\n- Rich item\r\n  - nested\r\n\r\n{$fence}markdown\r\n### Added\r\n{$fence}\r\n\r\n### Unknown\r\nOther  text.\r\n";
        $result = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: $legacy)]), '1.0.0', null, 'New prose.', $this->template());
        $body = $result->getRelease('1.0.0')->getBody();
        self::assertStringContainsString("Legacy  prose.\r\n\r\nNew prose.", $body);
        self::assertStringContainsString("- Rich item\r\n  - nested\r\n", $body);
        self::assertStringContainsString("{$fence}markdown\r\n### Added\r\n{$fence}\r\n", $body);
        self::assertStringContainsString("### Unknown\r\nOther  text.\r\n", $body);
    }

    /** Category aliases use the selected presentation while legacy prose and empty new categories are retained. */
    public function testCustomCategoriesAndProseOnlyNotesRemainSupported(): void
    {
        $template = $this->template('Corrections');
        $result = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: "### Corrections\n\n- Old fix.\n\n### Added\n")]), '1.0.0', null, "### Fixed\n\n- New fix.\n", $template);
        self::assertSame("### Corrections\n\n- Old fix.\n- New fix.\n", $result->getRelease('1.0.0')->getBody());
        $plain = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: 'Legacy prose.')]), '1.0.0', null, '', $template);
        self::assertSame('Legacy prose.', $plain->getRelease('1.0.0')->getBody());
        $localized = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: "### Corrigido\n\n- Localized fix.\n")]), '1.0.0', null, "### Fixed\n\n- New fix.\n", $template);
        self::assertSame("### Corrections\n\n- Localized fix.\n- New fix.\n", $localized->getRelease('1.0.0')->getBody());
    }

    /** New fixes stay under their category before the original following unknown peer blocks. */
    #[TestWith(['### Upgrade notes'])]
    #[TestWith(["  ###\tUpgrade notes"])]
    #[TestWith(['###'])]
    public function testNewCategoryEntriesPrecedeUnknownPeerBlocks(string $peer): void
    {
        $legacy = "### Fixed\n\n- Old fix.\n\n{$peer}\n\nPreserve upgrade instructions.\n\n### Compatibility\n\nOrdinary peer prose.\n\n### Added\n\n- Old addition.\n";
        $result = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: $legacy)]), '1.0.0', null, "### Fixed\n\n- New fix.\n", $this->template());
        self::assertSame("### Added\n\n- Old addition.\n\n### Fixed\n\n- Old fix.\n- New fix.\n\n{$peer}\n\nPreserve upgrade instructions.\n\n### Compatibility\n\nOrdinary peer prose.\n\n", $result->getRelease('1.0.0')->getBody());
    }

    /** Reserved legacy metadata is removed only outside fences while project comments and examples remain literal. */
    #[TestWith(['```'])]
    #[TestWith(['~~~'])]
    public function testLegacyMarkersDoNotPollutePromotedHistory(string $fence): void
    {
        $marker = '<!-- fast-forward-changelog:fragment {"id":"legacy.md","category":"fixed"} -->';
        $legacy = "<!-- project comment -->\n\n<!-- fast-forward-changelog:category fixed -->\n### Fixed\n\n{$marker}\n- Exact legacy note.\n\n{$fence}markdown\n<!-- fast-forward-changelog:category added -->\n{$marker}\n{$fence}\n";
        $result = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: $legacy)]), '1.0.0', null, "### Fixed\n\n- New fix.\n", $this->template());
        $body = $result->getRelease('1.0.0')->getBody();
        self::assertStringContainsString('<!-- project comment -->', $body);
        self::assertSame(1, substr_count($body, $marker));
        self::assertStringNotContainsString('<!-- fast-forward-changelog:category fixed -->', $body);
        self::assertStringContainsString("{$fence}markdown\n<!-- fast-forward-changelog:category added -->\n{$marker}\n{$fence}", $body);
        self::assertStringContainsString('- Exact legacy note.', $body);
        self::assertStringContainsString('- New fix.', $body);
    }

    /** Inline backtick spans cannot hide subsequent categories and reserved metadata as fenced data. */
    #[TestWith(['```foo``` bar'])]
    #[TestWith(['   ````lang`inline'])]
    public function testBacktickInfoStringsCannotOpenFences(string $inline): void
    {
        $legacy = $inline . "\n\n<!-- fast-forward-changelog:category fixed -->\n### Fixed\n\n<!-- fast-forward-changelog:fragment {} -->\n- Old fix.\n";
        $result = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: $legacy)]), '1.0.0', null, "### Fixed\n\n- New fix.\n", $this->template());
        $body = $result->getRelease('1.0.0')->getBody();
        self::assertStringStartsWith($inline . "\n\n", $body);
        self::assertStringNotContainsString('fast-forward-changelog:', $body);
        self::assertSame(1, substr_count($body, '### Fixed'));
        self::assertStringContainsString("- Old fix.\n- New fix.", $body);
    }

    /** Closing metadata is consumed while trailing prose and exact newline bytes survive even an empty body. */
    #[TestWith(["<!-- fast-forward-changelog:end-release -->\n\nTrailing  prose.\n", "\nTrailing  prose.\n", 'Pending notes.'])]
    #[TestWith(["<!-- fast-forward-changelog:end-release -->\r\n\r\nTrailing  prose.\r\n", "\r\nTrailing  prose.\r\n", ''])]
    #[TestWith(["\n\nUnmarked trailing prose.\n", "\n\nUnmarked trailing prose.\n", 'Pending notes.'])]
    public function testPendingEndingIsTransferredWithoutTheReservedDelimiter(string $ending, string $expected, string $body): void
    {
        $pending = new HistoryRelease('unreleased', body: $body, ending: $ending);
        $result = $this->service()->promote(new HistoryDocument([$pending]), '1.0.0', null, 'New notes.', $this->template());
        self::assertSame($expected, $result->getRelease('1.0.0')->getEnding());
        self::assertSame($ending, $pending->getEnding());
        self::assertNull($result->getRelease('unreleased'));
    }

    /** Explicit legacy category identity overrides unfamiliar presentation and merges new fixes under one selected heading. */
    #[TestWith([''])]
    #[TestWith(["\n\n"])]
    public function testMarkedCustomCategoryKeepsItsIdentity(string $spacing): void
    {
        $body = "<!-- fast-forward-changelog:category fixed -->\n{$spacing}### Bug fixes\n\n- Legacy fix.\n";
        $result = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: $body)]), '1.0.0', null, "### Corrections\n\n- New fix.\n", $this->template('Corrections'));
        self::assertSame("### Corrections\n\n- Legacy fix.\n- New fix.\n", $result->getRelease('1.0.0')->getBody());
    }

    /** A malformed or dangling category marker cannot silently reclassify or discard pending descriptions. */
    #[TestWith(["<!-- fast-forward-changelog:category fixed -->\nMissing heading.\n"])]
    #[TestWith(["<!-- fast-forward-changelog:category fixed -->\n\n"])]
    public function testInvalidCategoryMarkerFailsWithoutChangingTheSource(string $body): void
    {
        $document = new HistoryDocument([new HistoryRelease('unreleased', body: $body)]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Legacy category markers must be followed by a level-three heading.');
        try {
            $this->service()->promote($document, '1.0.0', null, '- New note.', $this->template());
        } finally {
            self::assertSame($body, $document->getRelease('unreleased')->getBody());
        }
    }

    /** Every pending value is consumed exactly once, including later bodies after an empty first section. */
    #[TestWith([''])]
    #[TestWith(["### Fixed\n\n- First fix.\n"])]
    public function testAllPendingSectionsAreConsolidated(string $first): void
    {
        $old = new HistoryRelease('0.9.0', body: 'Published bytes.');
        $document = new HistoryDocument([new HistoryRelease('unreleased', body: $first, ending: "\nFirst tail.\n"), $old, new HistoryRelease('unreleased', body: "### Fixed\n\n- Second fix.\n\n### Added\n\n- Addition.\n", ending: "\nSecond tail.\n")]);
        $result = $this->service()->promote($document, '1.0.0', null, "### Fixed\n\n- New fix.\n", $this->template());
        $expected = "### Added\n\n- Addition.\n\n### Fixed\n\n" . ('' === $first ? '' : "- First fix.\n") . "- Second fix.\n- New fix.\n";
        self::assertSame($expected, $result->getRelease('1.0.0')->getBody());
        self::assertSame("\nFirst tail.\n\nSecond tail.\n", $result->getRelease('1.0.0')->getEnding());
        self::assertSame([$result->getRelease('1.0.0'), $old], $result->getReleases());
        self::assertCount(3, $document->getReleases());
    }

    /** Selected category labels take precedence over every canonical alias even when their names collide. */
    public function testSelectedHeadingsOverrideCanonicalAliases(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturnCallback(static fn(string $category): string => '### ' . match ($category) {
            'added' => 'Fixed', 'fixed' => 'Bugs', default => ucfirst($category),
        });
        $legacy = "### Fixed\n\n- Old addition.\n\n### Bugs\n\n- Old fix.\n";
        $current = "### Fixed\n\n- New addition.\n\n### Bugs\n\n- New fix.\n";
        $result = $this->service()->promote(new HistoryDocument([new HistoryRelease('unreleased', body: $legacy)]), '1.0.0', null, $current, $template);
        self::assertSame("### Fixed\n\n- Old addition.\n- New addition.\n\n### Bugs\n\n- Old fix.\n- New fix.\n", $result->getRelease('1.0.0')->getBody());
    }

    /** Value construction is injected and no host or side-effect boundary is consulted. */
    private function service(): ReleaseHistoryConsolidator
    {
        $factory = $this->createStub(HistoryReleaseFactoryInterface::class);
        $factory->method('create')->willReturnCallback(static fn(string $version, ?string $date, ?string $source, string $body, ?string $heading = null, string $ending = ''): HistoryRelease => new HistoryRelease($version, $date, $source, $body, $heading, $ending));
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));
        return new ReleaseHistoryConsolidator($factory, $exceptions);
    }

    /** Supplies explicit category labels independently of production template behavior. */
    private function template(string $fixed = 'Fixed'): TemplateInterface
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturnCallback(static fn(string $category): string => '### ' . ('fixed' === $category ? $fixed : ucfirst($category)));
        return $template;
    }
}
