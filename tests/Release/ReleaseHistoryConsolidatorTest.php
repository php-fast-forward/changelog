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
use FastForward\Changelog\Release\ReleaseHistoryConsolidator;
use FastForward\Changelog\Template\TemplateInterface;
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
        self::assertStringContainsString("Legacy  prose.\n\nNew prose.", $body);
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

    /** Value construction is injected and no host or side-effect boundary is consulted. */
    private function service(): ReleaseHistoryConsolidator
    {
        $factory = $this->createStub(HistoryReleaseFactoryInterface::class);
        $factory->method('create')->willReturnCallback(static fn(string $version, ?string $date, ?string $source, string $body): HistoryRelease => new HistoryRelease($version, $date, $source, $body));
        return new ReleaseHistoryConsolidator($factory);
    }

    /** Supplies explicit category labels independently of production template behavior. */
    private function template(string $fixed = 'Fixed'): TemplateInterface
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturnCallback(static fn(string $category): string => '### ' . ('fixed' === $category ? $fixed : ucfirst($category)));
        return $template;
    }
}
