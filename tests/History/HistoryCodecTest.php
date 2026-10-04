<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryExceptionFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodec;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Template\Factory\TemplateFactory;
use FastForward\Changelog\Template\KeepAChangelogTemplate;
use FastForward\Changelog\Template\TemplateInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryCodec::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(TemplateFactory::class)]
#[UsesClass(KeepAChangelogTemplate::class)]
#[UsesClass(Category::class)]
final class HistoryCodecTest extends TestCase
{
    #[Test]
    public function preservesCrLfDescriptionsFencesAndAmbiguousReferenceFooterProse(): void
    {
        $body = "\r\n### Added\r\n\r\n- [Feature][issue]\r\n  nested prose\r\n\r\n```markdown\r\n## [99.0.0] - 2026-01-01\r\n[example]: https://example.test\r\n```\r\n\r\n";
        $previous = "\r\n### Unknown presentation\r\n\r\nArbitrary old release prose.\r\n\r\n~~~text\r\n## [88.0.0]\r\n~~~\r\n\r\n";
        $prefix = "# Project history\r\n\r\nCustom introductory prose.\r\n\r\n";
        $footer = "[issue]: https://example.test/issues/123\r\n[1.0.0]: https://example.test/tag/v1.0.0\r\n\r\nFooter license prose.\r\n";
        $markdown = $prefix . "## [Unreleased]\r\n" . $body . "## [v1.0.0] - 2026-01-01\r\n" . $previous . $footer;
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertCount(2, $document->getReleases());
        self::assertSame($prefix, $document->getPrefix());
        self::assertSame('', $document->getReferences());
        self::assertSame($previous . $footer, $codec->notes($document, '1.0.0'));
        self::assertSame($body, $codec->notes($document, 'unreleased'));
        self::assertSame('2026-01-01', $document->getRelease('1.0.0')->getDate());
        self::assertSame($markdown, $codec->render($document, $this->template(), true));
    }

    #[Test]
    #[TestWith(["\n", 'guide'])]
    #[TestWith(["\r\n", 'guide'])]
    #[TestWith(["\n", '1.0.0'])]
    #[TestWith(["\r\n", '1.0.0'])]
    public function keepsInBodyReferenceDefinitionsAndFollowingProseWithinTheFinalLegacyRelease(string $ending, string $label): void
    {
        $body = $ending . '## [Migration guide](migration.md)' . $ending . $ending
            . 'Read the [migration][' . $label . '] before upgrading.' . $ending
            . '[' . $label . ']: https://example.test/migration "Migration title"' . $ending . $ending
            . 'The release description continues here.  ' . $ending . $ending
            . '1. Keep the ordered list.' . $ending . '   Preserve its **nested description**.' . $ending . $ending
            . '```markdown' . $ending . '[example]: https://example.test/code' . $ending
            . '## [99.0.0] - invalid-date' . $ending . '```' . $ending . $ending
            . 'The last paragraph is still release prose.' . $ending;
        $markdown = '# Custom history' . $ending . $ending . '## [v1.0.0] - 2026-10-03' . $ending . $body;
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertCount(1, $document->getReleases());
        self::assertSame('', $document->getReferences());
        self::assertSame($body, $codec->notes($document, '1.0.0'));
        self::assertSame($markdown, $codec->render($document, $this->template('en'), true));

        $formatted = $codec->parse($codec->render($document, $this->template('en')));
        self::assertSame('', $formatted->getReferences());
        self::assertSame($body, $codec->notes($formatted, '1.0.0'));
    }

    #[Test]
    #[TestWith(["\n", ''])]
    #[TestWith(["\n", "\n"])]
    #[TestWith(["\r\n", ''])]
    #[TestWith(["\r\n", "\r\n"])]
    public function separatesOnlyTheTrueTrailingReferenceBlockFromEarlierBodyDefinitions(string $ending, string $finalEnding): void
    {
        $body = $ending . 'See the [guide][migration].' . $ending
            . '[migration]: https://example.test/migration' . $ending . $ending
            . 'More release prose follows the in-body definition.' . $ending . $ending
            . '```markdown' . $ending . '[example]: https://example.test/code' . $ending . '```' . $ending . $ending;
        $footer = '[1.0.0]: https://example.test/tag/v1.0.0' . $ending . " \t" . $ending
            . '[issue]: https://example.test/issues/123' . $finalEnding;
        $markdown = '## [1.0.0]' . $ending . $body . $footer;
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertSame($body, $codec->notes($document, '1.0.0'));
        self::assertSame($footer, $document->getReferences());
        self::assertSame($markdown, $codec->render($document, $this->template('en'), true));

        $formatted = $codec->parse($codec->render($document, $this->template('en')));
        self::assertSame($body, $codec->notes($formatted, '1.0.0'));
        self::assertSame($footer, $formatted->getReferences());
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function markedBodiesKeepTheirOwnReferencesAndExcludeExplicitFooterBoilerplate(bool $byteFramed): void
    {
        $body = "Notes use the [migration][guide].\r\n\r\n[guide]: https://example.test/migration\r\n";
        $metadata = ['version' => '1.0.0'];
        if ($byteFramed) {
            $metadata['body_length'] = strlen($body);
        }
        $footer = "[1.0.0]: https://example.test/tag/v1.0.0\r\n\r\nFooter license prose.\r\n";
        $markdown = '<!-- fast-forward-changelog:release ' . json_encode($metadata, JSON_THROW_ON_ERROR)
            . " -->\r\n## [1.0.0]\r\n" . $body . "<!-- fast-forward-changelog:end-release -->\r\n" . $footer;
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertSame($body, $codec->notes($document, '1.0.0'));
        self::assertSame($markdown, $codec->render($document, $this->template('en'), true));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous with the global footer');
        $codec->render($document, $this->template('en'));
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(["\r\n \t\r\n"])]
    #[TestWith(['Unstructured raw history without supported release headings.'])]
    #[TestWith(["# Custom\n\n[link]: https://example.test\n\nfooter\n"])]
    #[TestWith(["# Custom\n\n## [Migration guide](migration.md)\n\nPreserve this prose.\n"])]
    public function retainsUnstructuredHistory(string $markdown): void
    {
        $codec = $this->codec();
        $document = $codec->parse($markdown);
        self::assertSame([], $document->getReleases());

        if ('' !== $markdown) {
            self::assertSame($markdown, $codec->render($document, $this->template(), true));
        } else {
            self::assertStringContainsString('Intro pt-BR', $codec->render($document, $this->template()));
        }
    }

    #[Test]
    public function preservesUnknownLinkedHeadingsWithinHistoricalProse(): void
    {
        $prefix = "# Package history\r\n\r\n## [Migration guide](migration.md)\r\n\r\nSee the migration examples.\r\n\r\n## [vNext]\r\nA custom roadmap stays prose.\r\n\r\n";
        $pending = "\r\n## [Overview](overview.md)\r\n\r\n- A pending description.  \r\n\r\n";
        $body = "\r\n## [Migration guide](migration.md)\r\n\r\n1. Keep the nested list.\r\n   Continue the description.  \r\n\r\n"
            . "## [Security considerations]\r\nPreserve **rich Markdown** and [inline links](security.md).\r\n\r\n"
            . "## [2026 roadmap](roadmap.md)\r\nFuture prose.\r\n\r\n## [1. Documentation](docs.md)\r\nA numbered topic.\r\n\r\n"
            . "```markdown\r\n## [2.0.0] - invalid-date\r\n```\r\n";
        $markdown = $prefix . "## [Não publicado]\r\n" . $pending . "## [v1.2.3](releases/v1.2.3) - 2026-10-03\r\n" . $body;
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertSame(['unreleased', '1.2.3'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $document->getReleases()));
        self::assertSame($prefix, $document->getPrefix());
        self::assertSame($pending, $codec->notes($document, 'unreleased'));
        self::assertSame($body, $codec->notes($document, '1.2.3'));
        self::assertSame($markdown, $codec->render($document, $this->template('en'), true));

        $formatted = $codec->parse($codec->render($document, $this->template('en')));
        self::assertSame($prefix, $formatted->getPrefix());
        self::assertSame($pending, $codec->notes($formatted, 'unreleased'));
        self::assertSame($body, $codec->notes($formatted, '1.2.3'));
    }

    #[Test]
    #[TestWith(['0.0.0', '0.0.0'])]
    #[TestWith(['v1.2.3', '1.2.3'])]
    #[TestWith(['V1.2.3-rc.1+build.7', '1.2.3-rc.1+build.7'])]
    #[TestWith(['1.0.0-alpha.beta', '1.0.0-alpha.beta'])]
    #[TestWith(['1.0.0+docs.01', '1.0.0+docs.01'])]
    public function stillRecognizesLinkedSemanticVersionHeadings(string $version, string $canonical): void
    {
        $markdown = '## [' . $version . "](releases.md) - 2026-10-03 [YANKED]\nExact release notes.\n";
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertCount(1, $document->getReleases());
        self::assertSame("Exact release notes.\n", $codec->notes($document, $canonical));
        self::assertSame('2026-10-03', $document->getRelease($canonical)->getDate());
        self::assertSame($markdown, $codec->render($document, $this->template('en'), true));
    }

    #[Test]
    #[TestWith(['Unreleased'])]
    #[TestWith(['unreleased'])]
    #[TestWith(['Não publicado'])]
    #[TestWith(['não publicado'])]
    public function stillRecognizesEveryExistingUnreleasedAlias(string $label): void
    {
        $markdown = '## [' . $label . "](compare.md)\nPending notes.\n";
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertCount(1, $document->getReleases());
        self::assertSame("Pending notes.\n", $codec->notes($document, 'unreleased'));
        self::assertSame($markdown, $codec->render($document, $this->template(), true));
    }

    #[Test]
    #[TestWith(["## [1.2.3] - 2026-10-xx\n"])]
    #[TestWith(["## [Unreleased] - yesterday\n"])]
    #[TestWith(["## [v1.2.3](broken-link\n"])]
    #[TestWith(["## [V1.2.3] - 2026-10-03 unexpected suffix\n"])]
    public function rejectsMalformedRecognizedReleaseHeadings(string $heading): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A legacy release heading must use a bracketed version');
        $this->codec()->parse($heading . "Notes stay available in the source.\n");
    }

    #[Test]
    #[TestWith(['01.2.3', null])]
    #[TestWith(['v01.2.3', null])]
    #[TestWith(['1.2', null])]
    #[TestWith(['1.', null])]
    #[TestWith(['1.2.3-01', null])]
    #[TestWith(['1.2.3-', null])]
    #[TestWith(['1.2.3+build..1', null])]
    #[TestWith(['1.2.3', '2026-02-30'])]
    public function preservesFactoryAuthorityForMalformedVersionsAndCalendarDates(string $version, ?string $date): void
    {
        $heading = '## [' . $version . ']' . (null === $date ? '' : ' - ' . $date) . "\n";
        $releases = $this->createMock(HistoryReleaseFactoryInterface::class);
        $releases->expects(self::once())->method('create')
            ->with($version, $date, null, "Exact notes.\n", $heading, '')
            ->willThrowException(new InvalidArgumentException('The release factory rejected this version or calendar date.'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The release factory rejected this version or calendar date.');
        $this->codec($releases)->parse($heading . "Exact notes.\n");
    }

    #[Test]
    #[TestWith(['2026-10-03'])]
    #[TestWith([null])]
    public function customPlainPresentationRoundTripsWithTheSelectedTemplate(?string $date): void
    {
        $codec = $this->codec();
        $template = $this->template('custom');
        $body = "\n### Added\n\nRaw release text with [links](https://example.test).\n\n## [Migration guide]\nRead this guide.\n\n```md\n## [9.0.0]\n### Added\n```\n";
        $document = new HistoryDocument([new HistoryRelease('1.2.3', $date, 'published_at', $body)]);
        $output = $codec->render($document, $template);
        $parsed = $codec->parse($output, $template);

        self::assertCount(1, $parsed->getReleases());
        self::assertSame($date, $parsed->getRelease('1.2.3')->getDate());
        self::assertNull($parsed->getRelease('1.2.3')->getDateSource());
        self::assertStringNotContainsString('fast-forward-changelog:', $output);
        self::assertStringContainsString('### Custom added', $codec->notes($parsed, '1.2.3'));
        self::assertStringContainsString("```md\n## [9.0.0]\n### Added\n```", $codec->notes($parsed, '1.2.3'));
        self::assertSame($output, $codec->render($parsed, $template, true));
        self::assertSame($output, $codec->render($parsed, $template));
    }

    #[Test]
    public function selectedCustomPendingHeadingIsRecognizedWithoutTechnicalMarkers(): void
    {
        $template = $this->template('custom');
        $markdown = "## Pending updates\n\n### Custom added\n- A pending feature.\n";
        $codec = $this->codec();
        self::assertSame([], $codec->parse($markdown)->getReleases());
        $document = $codec->parse($markdown, $template);
        self::assertSame('unreleased', $document->getReleases()[0]->getVersion());
        self::assertSame($markdown, $codec->render($document, $template, true));
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith(['2026-10-03'])]
    public function selectedCustomBracketedHeadingTakesPriorityOverTheStandardGrammar(?string $date): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('releaseHeading')->willReturnCallback(static fn(string $version, ?string $date): string => '## [' . $version . ']' . (null === $date ? ' (archived)' : ' released on ' . $date));
        $markdown = $template->releaseHeading('1.2.3', $date) . "\nExact notes.\n";
        $codec = $this->codec();
        $release = $codec->parse($markdown, $template)->getRelease('1.2.3');
        self::assertSame($date, $release->getDate());
        self::assertSame("Exact notes.\n", $release->getBody());
    }

    #[Test]
    public function customRepeatedPlaceholdersRequireMatchingVersionAndDate(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('releaseHeading')->willReturnCallback(static fn(string $version, ?string $date): string => '## ' . $version . ' / ' . $version . (null === $date ? '' : ': ' . $date . ' / ' . $date));
        $codec = $this->codec();
        $dated = "## 1.2.3 / 1.2.3: 2026-10-03 / 2026-10-03\nExact notes.\n";
        $document = $codec->parse($dated, $template);
        self::assertSame('2026-10-03', $document->getRelease('1.2.3')->getDate());
        self::assertSame("Exact notes.\n", $codec->notes($document, '1.2.3'));
        self::assertNull($codec->parse("## 1.2.3 / 1.2.3\nExact notes.\n", $template)->getRelease('1.2.3')->getDate());
    }

    #[Test]
    #[TestWith(['/ 1.2.3:', '/ 2.0.0:'])]
    #[TestWith(['/ 2026-10-03', '/ 2026-10-04'])]
    public function refusesDivergentRepeatedPlaceholdersInsteadOfHidingTheRelease(string $original, string $replacement): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('releaseHeading')->willReturnCallback(static fn(string $version, ?string $date): string => '## ' . $version . ' / ' . $version . (null === $date ? '' : ': ' . $date . ' / ' . $date));
        $markdown = "## 1.2.3 / 1.2.3: 2026-10-03 / 2026-10-03\nExact notes.\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keep the matching release template or migrate historical release headings');
        $this->codec()->parse(str_replace($original, $replacement, $markdown), $template);
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith(['en'])]
    #[TestWith(['pt-BR'])]
    #[TestWith(['custom'])]
    public function refusesChangingTemplatesBeforeMigratingExistingCustomHistory(?string $locale): void
    {
        $previous = new TemplateFactory()->create('en', ['introduction' => '# Project history', 'release_heading' => '## Release {version}', 'release_heading_dated' => '## Release {version} on {date}']);
        $codec = $this->codec();
        $markdown = $codec->render(new HistoryDocument([
            new HistoryRelease('1.2.3', '2026-10-03', body: "Maintained release notes.\n"),
            new HistoryRelease('1.1.0', body: "Earlier release notes.\n"),
        ]), $previous);
        $parsed = $codec->parse($markdown, $previous);
        self::assertSame(['1.2.3', '1.1.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame("Maintained release notes.\n", $codec->notes($parsed, '1.2.3'));
        self::assertSame($markdown, $codec->render($parsed, $previous, true));

        $releases = $this->createMock(HistoryReleaseFactoryInterface::class);
        $releases->expects(self::never())->method('create');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An unrecognized level-two heading resembles a release');
        $this->codec($releases)->parse($markdown, null === $locale ? null : $this->template($locale));
    }

    #[Test]
    public function recognizesReviewedHeadingMigrationWithoutDuplicatingCustomHistory(): void
    {
        $previous = new TemplateFactory()->create('en', ['release_heading' => '## Release {version}', 'release_heading_dated' => '## Release {version} on {date}']);
        $markdown = "# Project history\n\n## Release 1.2.3 on 2026-10-03\nMaintained notes.\n\n## Release 1.1.0\nEarlier notes.\n";
        $codec = $this->codec();
        $migrated = $codec->render($codec->parse($markdown, $previous), $this->template('en'));
        $parsed = $codec->parse($migrated, $this->template('en'));

        self::assertSame(['1.2.3', '1.1.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame("Maintained notes.\n\n", $codec->notes($parsed, '1.2.3'));
        self::assertSame("Earlier notes.\n", $codec->notes($parsed, '1.1.0'));
        self::assertSame(1, substr_count($migrated, '## [1.2.3]'));
        self::assertSame(1, substr_count($migrated, '## [1.1.0]'));
        self::assertStringNotContainsString('fast-forward-changelog:', $migrated);
    }

    #[Test]
    #[TestWith(['## Release 1.2.3 on 2026-10-03'])]
    #[TestWith(['## Published on 2026-10-03: v1.2.3'])]
    #[TestWith(['## Versão V1.2.3-rc.1+build.007'])]
    #[TestWith(['## Stable edition 999999999999999999999999.123456789012345678901234.0'])]
    #[TestWith(['## Release (1.2.3)'])]
    #[TestWith(['## Compatibility with PHP 8.5.0'])]
    #[TestWith(['## Compatibility with PHP 8.5.0.1'])]
    #[TestWith(['## Invalid version 01.2.3'])]
    #[TestWith(['## Invalid version 1.2.3-01'])]
    public function refusesUnsupportedVersionShapedHeadingsBeforeConstructingAnyReleases(string $heading): void
    {
        $releases = $this->createMock(HistoryReleaseFactoryInterface::class);
        $releases->expects(self::never())->method('create');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('For Markdown prose or examples, fence or nest this heading.');
        $this->codec($releases)->parse("## [2.0.0]\nKnown notes.\n" . $heading . "\nOld notes stay available in the source.\n", $this->template('en'));
    }

    #[Test]
    #[TestWith(['## Release{version}'])]
    #[TestWith(['## r{version}notes'])]
    #[TestWith(['## Step0{version}'])]
    #[TestWith(['## {version}.4'])]
    #[TestWith(['## {version}{version}'])]
    public function refusesHidingCompactHistoryCreatedByAnAcceptedPreviousTemplate(string $heading): void
    {
        $previous = new TemplateFactory()->create('en', ['release_heading' => $heading]);
        $codec = $this->codec();
        $markdown = $codec->render(new HistoryDocument([new HistoryRelease('1.2.3', body: "Exact compact release notes.\n")]), $previous);
        $parsed = $codec->parse($markdown, $previous);

        self::assertSame(['1.2.3'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame("Exact compact release notes.\n", $codec->notes($parsed, '1.2.3'));
        self::assertSame($markdown, $codec->render($parsed, $previous, true));

        $releases = $this->createMock(HistoryReleaseFactoryInterface::class);
        $releases->expects(self::never())->method('create');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keep the matching release template or migrate historical release headings');
        $this->codec($releases)->parse($markdown, new TemplateFactory()->create());
    }

    #[Test]
    public function refusesUnknownCompactHeadingWithALongNumericSuffix(): void
    {
        $releases = $this->createMock(HistoryReleaseFactoryInterface::class);
        $releases->expects(self::never())->method('create');
        $markdown = "## [2.0.0]\nKnown notes.\n## Release1.2.3" . str_repeat('7', 100_000) . "\nOld release notes.\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An unrecognized level-two heading resembles a release');
        $this->codec($releases)->parse($markdown, $this->template('en'));
    }

    #[Test]
    public function refusesUnsupportedReleaseShapedMarkdownBeforeRenderingNewHistory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An unrecognized level-two heading resembles a release');
        $this->codec()->render(new HistoryDocument([new HistoryRelease('2.0.0', body: "Description.\n## Release1.2.3\nHistorical example.\n")]), $this->template('en'));
    }

    #[Test]
    public function preservesVersionTextInIntroductoryNestedAndFencedMarkdown(): void
    {
        $prefix = "# Compatibility with 8.5.0\r\n\r\nIntroductory prose mentions 1.2.3 without introducing a release.\r\n\r\n"
            . "### Release 3.0.0 on 2026-10-03\r\n> ## Release 3.0.0\r\n- Nested example\r\n  ## Release 3.0.0\r\n- ## Release 3.0.0\r\n    ## Release 3.0.0\r\n\r\n"
            . "```markdown\r\n## Release 3.0.0 on 2026-10-03\r\n```\r\n\r\n";
        $body = "\r\nPlain body prose about 1.2.3 stays exact.\r\n### Release V1.2.3-rc.1+build.7\r\n"
            . "> ## Release 1.2.3\r\n- Nested example\r\n  ## Release 1.2.3\r\n- ## Release 1.2.3\r\n    ## Release 1.2.3\r\n\r\n"
            . "~~~markdown\r\n## Published on 2026-10-03: 1.2.3\r\n~~~\r\n"
            . "## API 1.2 compatibility\r\n## Release roadmap\r\n## Description 2026-10-03\r\n";
        $markdown = $prefix . "## [1.0.0] - 2026-10-03\r\n" . $body;
        $codec = $this->codec();
        $parsed = $codec->parse($markdown, $this->template('en'));

        self::assertSame(['1.0.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame($prefix, $parsed->getPrefix());
        self::assertSame($body, $codec->notes($parsed, '1.0.0'));
        self::assertSame($markdown, $codec->render($parsed, $this->template('en'), true));
        $formatted = $codec->parse($codec->render($parsed, $this->template('en')), $this->template('en'));
        self::assertSame($prefix, $formatted->getPrefix());
        self::assertSame($body, $codec->notes($formatted, '1.0.0'));
    }

    #[Test]
    public function convertsLocalizedStructureBackToEnglishWithoutChangingDescriptions(): void
    {
        $codec = $this->codec();
        $document = $codec->parse("# Custom prose stays\n\n## [Não publicado]\n\n### Adicionado\n- descrição permanece\n\n## [1.0.0] - 2026-10-03 [REMOVIDO]\n\n### Corrigido\n- correction\n### Unknown\nProse remains.\n");
        $output = $codec->render($document, $this->template('en'));

        self::assertStringStartsWith('# Custom prose stays', $output);
        self::assertStringContainsString('## [Unreleased]', $output);
        self::assertStringContainsString('### Added', $output);
        self::assertStringContainsString('### Fixed', $output);
        self::assertStringContainsString('descrição permanece', $output);
        self::assertStringContainsString('### Unknown', $output);
        self::assertStringContainsString('[REMOVIDO]', $output);
    }

    #[Test]
    public function replacesOnlyKnownIntroductoryBoilerplate(): void
    {
        $codec = $this->codec();
        $prefix = "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\nThe format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),\nand this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).\n\nAdditional custom introductory prose.\n\n";
        $document = $codec->parse($prefix . "## [1.0.0]\n\n### Added\n- entry\n");
        $output = $codec->render($document, $this->template());
        self::assertStringContainsString('Intro pt-BR', $output);
        self::assertStringContainsString('Additional custom introductory prose.', $output);
        self::assertStringNotContainsString('All notable changes', $output);
    }

    #[Test]
    public function newSectionsPreserveRawNotesAndDateProvenanceIncrementally(): void
    {
        $codec = $this->codec();
        $document = new HistoryDocument([new HistoryRelease('1.0.0', null, null, 'raw notes')], " \n");
        $output = $codec->render($document, $this->template(), true);
        $parsed = $codec->parse($output);
        self::assertSame("raw notes\n", $codec->notes($parsed, '1.0.0'));
        self::assertNull($parsed->getRelease('1.0.0')->getDate());
        self::assertNull($parsed->getRelease('1.0.0')->getDateSource());
    }

    #[Test]
    public function legacyByteFramingStillReadsEmbeddedDelimitersAndFalseReleaseHeadings(): void
    {
        $body = "First paragraph.\n<!-- fast-forward-changelog:end-release -->\n## [9.9.9] - 2020-01-01\n"
            . "<!-- fast-forward-changelog:release {\"version\":\"8.8.8\"} -->\n## Fake release\n"
            . "<!-- fast-forward-changelog:end-release -->\n[internal]: https://example.test\nLast paragraph.\n";
        $metadata = json_encode(['version' => '1.0.0', 'body_length' => strlen($body)], JSON_THROW_ON_ERROR);
        $markdown = '<!-- fast-forward-changelog:release ' . $metadata . " -->\n## [1.0.0]\n"
            . $body . "<!-- fast-forward-changelog:end-release -->\n";
        $codec = $this->codec();
        $parsed = $codec->parse($markdown);

        self::assertSame(['1.0.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame($body, $codec->notes($parsed, '1.0.0'));
        self::assertSame('', $parsed->getReferences());
        self::assertSame($markdown, $codec->render($parsed, $this->template('en'), true));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unfenced release heading or legacy release delimiter');
        $codec->render($parsed, $this->template('en'));
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(['Unicode descrição e segurança 🐘'])]
    #[TestWith(["Unicode descrição e segurança 🐘\n"])]
    #[TestWith(["Descrição com espaços.  \r\n\r\nÚltima linha.\r\n"])]
    public function plainSectionsRetainUnicodeAndNewlineBytesWithoutMetadata(string $body): void
    {
        $codec = $this->codec();
        $expected = '' === $body || str_ends_with($body, "\n") ? $body : $body . "\n";
        $output = $codec->render(new HistoryDocument([new HistoryRelease('1.0.0', body: $body)]), $this->template('en'), true);
        self::assertStringNotContainsString('fast-forward-changelog:', $output);
        self::assertSame($expected, $codec->notes($codec->parse($output), '1.0.0'));
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([true])]
    #[TestWith(['5'])]
    #[TestWith([1.5])]
    #[TestWith([-1])]
    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([4])]
    #[TestWith([6])]
    #[TestWith([PHP_INT_MAX])]
    public function rejectsMalformedOrMismatchedBodyLengths(mixed $length): void
    {
        $metadata = json_encode(['version' => '1.0.0', 'body_length' => $length], JSON_THROW_ON_ERROR);
        $markdown = '<!-- fast-forward-changelog:release ' . $metadata . " -->\n## [1.0.0]\ntext\n<!-- fast-forward-changelog:end-release -->\n";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('body_length');
        $this->codec()->parse($markdown);
    }

    #[Test]
    public function rejectsAnAbsentClosingDelimiterAfterAnExactlySizedBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('body_length');
        $this->codec()->parse("<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"body_length\":5} -->\n## [1.0.0]\ntext\n");
    }

    #[Test]
    public function acceptsSafeLegacyFramingAndMigratesItWithoutChangingNotes(): void
    {
        $body = "Legacy notes.\n\n```md\n<!-- fast-forward-changelog:end-release -->\n## [9.9.9]\n```\n";
        $legacy = "<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n## [1.0.0]\n" . $body
            . "<!-- fast-forward-changelog:end-release -->\n";
        $codec = $this->codec();
        $parsed = $codec->parse($legacy);
        self::assertSame($body, $codec->notes($parsed, '1.0.0'));
        $migrated = $codec->render($parsed, $this->template('en'));
        self::assertStringNotContainsString('fast-forward-changelog:release ', $migrated);
        self::assertSame(1, substr_count($migrated, '<!-- fast-forward-changelog:end-release -->'));
        self::assertSame($body, $codec->notes($codec->parse($migrated), '1.0.0'));
    }

    #[Test]
    public function rejectsAmbiguousLegacyDelimitersInsteadOfTruncatingNotes(): void
    {
        $legacy = "<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n## [1.0.0]\nFirst paragraph.\n"
            . "<!-- fast-forward-changelog:end-release -->\n## [9.9.9] - 2020-01-01\nInjected paragraph.\n"
            . "<!-- fast-forward-changelog:end-release -->\n";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous closing delimiters');
        $this->codec()->parse($legacy);
    }

    #[Test]
    public function mixedLegacyAndByteFramedSectionsRetainEmbeddedControlText(): void
    {
        $body = "New body.\n<!-- fast-forward-changelog:end-release -->\n## [9.9.9]\n";
        $metadata = json_encode(['version' => '2.0.0', 'body_length' => strlen($body)], JSON_THROW_ON_ERROR);
        $markdown = "<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n## [1.0.0]\nLegacy body.\n"
            . "<!-- fast-forward-changelog:end-release -->\n<!-- fast-forward-changelog:release " . $metadata . " -->\n## [2.0.0]\n" . $body
            . "<!-- fast-forward-changelog:end-release -->\n";
        $codec = $this->codec();
        $parsed = $codec->parse($markdown);
        self::assertSame(['1.0.0', '2.0.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame("Legacy body.\n", $codec->notes($parsed, '1.0.0'));
        self::assertSame($body, $codec->notes($parsed, '2.0.0'));
    }

    #[Test]
    public function supportsEmptyLinkedAndUndatedSections(): void
    {
        $codec = $this->codec();
        $document = $codec->parse("## [1.0.0](https://example.test/releases/v1.0.0)\n");
        self::assertSame('', $codec->notes($document, '1.0.0'));
        $output = $codec->render(new HistoryDocument([new HistoryRelease('unreleased')]), $this->template());
        self::assertSame('', $codec->notes($codec->parse($output), 'unreleased'));
    }

    #[Test]
    #[TestWith(["<!-- fast-forward-changelog:release {} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release 42 -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release invalid-json -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"date\":42} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"date_source\":42} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"unknown\":true} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\ntext\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n<!-- fast-forward-changelog:release {\"version\":\"2.0.0\"} -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n\n## Custom\nbody\n"])]
    public function rejectsMalformedOrUnterminatedReleaseMarkers(string $markdown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec()->parse($markdown);
    }

    #[Test]
    #[TestWith(["<!-- fast-forward-changelog:category added -->\nprose\n"])]
    #[TestWith(["<!-- fast-forward-changelog:category added -->\n"])]
    #[TestWith(["```md\nUnclosed example\n"])]
    #[TestWith(["## [9.0.0]\nA release-like example.\n"])]
    #[TestWith(["## Release 9.0.0: 2026-10-03\nA custom release-like example.\n"])]
    #[TestWith(["[guide]: https://example.test\n\n"])]
    public function refusesAmbiguousReformattingBeforeAnyIo(string $body): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec()->render(new HistoryDocument([new HistoryRelease('1.0.0', null, null, $body)]), $this->template('custom'));
    }

    #[Test]
    public function removesOnlyReservedGeneratedMarkersWhenMigratingSafeLegacyHistory(): void
    {
        $body = "\n<!-- fast-forward-changelog:category added -->\n### Features\n\n"
            . "<!-- fast-forward-changelog:fragment {\"id\":\"one.md\",\"category\":\"added\"} -->\n- Keep this **description**.\n"
            . "<!-- project-specific comment -->\n"
            . "```md\n<!-- fast-forward-changelog:fragment {\"id\":\"example.md\"} -->\n```\n";
        $metadata = json_encode(['version' => '1.0.0', 'date' => '2026-10-03', 'date_source' => 'manual', 'body_length' => strlen($body)], JSON_THROW_ON_ERROR);
        $prefix = "<!-- fast-forward-changelog:introduction -->\nOld introduction.\n<!-- /fast-forward-changelog:introduction -->\n\nCustom project prose.\n\n";
        $markdown = $prefix . '<!-- fast-forward-changelog:release ' . $metadata . " -->\n## Release 1.0.0\n"
            . $body . "<!-- fast-forward-changelog:end-release -->\n\n[1.0.0]: https://example.test/v1.0.0\n";
        $codec = $this->codec();
        $document = $codec->parse($markdown);
        self::assertSame('manual', $document->getRelease('1.0.0')->getDateSource());
        self::assertSame($markdown, $codec->render($document, $this->template('en'), true));
        $migrated = $codec->render($document, $this->template('en'));
        self::assertStringStartsWith("Intro en\n\nCustom project prose.", $migrated);
        self::assertStringContainsString("## [1.0.0] - 2026-10-03\n\n### Added", $migrated);
        self::assertStringContainsString('- Keep this **description**.', $migrated);
        self::assertStringContainsString('<!-- project-specific comment -->', $migrated);
        self::assertStringNotContainsString('fast-forward-changelog:release', $migrated);
        self::assertStringNotContainsString('fast-forward-changelog:category', $migrated);
        self::assertStringNotContainsString('fast-forward-changelog:introduction', $migrated);
        self::assertStringNotContainsString('"id":"one.md"', $migrated);
        self::assertStringContainsString("```md\n<!-- fast-forward-changelog:fragment {\"id\":\"example.md\"} -->\n```", $migrated);
        self::assertStringEndsWith("[1.0.0]: https://example.test/v1.0.0\n", $migrated);
        self::assertSame($migrated, $codec->render($codec->parse($migrated), $this->template('en'), true));
    }

    #[Test]
    public function missingReleaseHasAnExplicitDiagnostic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec()->notes(new HistoryDocument(), '9.0.0');
    }

    /** Returns the codec with all service-construction boundaries replaced by doubles. */
    private function codec(?HistoryReleaseFactoryInterface $releases = null): HistoryCodec
    {
        $documents = $this->createStub(HistoryDocumentFactoryInterface::class);
        $documents->method('create')->willReturnCallback(static fn(array $releases, string $prefix, string $references): HistoryDocument => new HistoryDocument($releases, $prefix, $references));
        if (null === $releases) {
            $releases = $this->createStub(HistoryReleaseFactoryInterface::class);
            $releases->method('create')->willReturnCallback(static fn(string $version, ?string $date, ?string $dateSource, string $body, ?string $heading, string $ending): HistoryRelease => new HistoryRelease(preg_replace('/^[vV](?=\d)/', '', $version), $date, $dateSource, $body, $heading, $ending));
        }
        $errors = $this->createStub(HistoryExceptionFactoryInterface::class);
        $errors->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));

        return new HistoryCodec($documents, $releases, $errors);
    }

    /** Returns a presentation double without invoking the concrete template factory. */
    private function template(string $locale = 'pt-BR'): TemplateInterface
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('getLocale')->willReturn($locale);
        $template->method('introduction')->willReturn('Intro ' . $locale);
        $template->method('unreleasedHeading')->willReturn('custom' === $locale ? '## Pending updates' : ('en' === $locale ? '## [Unreleased]' : '## [Não publicado]'));
        $template->method('releaseHeading')->willReturnCallback(static fn(string $version, ?string $date): string => 'custom' === $locale ? '## Release ' . $version . (null === $date ? '' : ': ' . $date) : '## [' . $version . ']' . (null === $date ? '' : ' - ' . $date));
        $template->method('categoryHeading')->willReturnCallback(static fn(string $category): string => match ($locale) {
            'custom' => '### Custom ' . $category,
            'en' => '### ' . ucfirst($category),
            default => '### ' . ['added' => 'Adicionado', 'fixed' => 'Corrigido', 'changed' => 'Modificado', 'deprecated' => 'Obsoleto', 'removed' => 'Removido', 'security' => 'Segurança'][$category],
        });

        return $template;
    }
}
