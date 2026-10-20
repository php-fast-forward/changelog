<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History;

use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodec;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryReferenceBuilder;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Template\TemplateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryReferenceBuilder::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(HistoryCodec::class)]
final class HistoryReferenceBuilderTest extends TestCase
{
    /** Missing links follow semantic predecessors and section order while retaining custom definition bytes. */
    public function testCompletesConcreteVersionsAndRetainsAllExistingBytes(): void
    {
        $footer = "[issue]: https://example.test/42 \r\n[1.0.0]: https://custom.test/first \"Title\"\r\n";
        $releases = [new HistoryRelease('1.2.0'), new HistoryRelease('unreleased'), new HistoryRelease('1.0.0'),
            new HistoryRelease('1.1.0')];
        $document = new HistoryDocument($releases, '# Exact introduction', $footer);
        $builder = new HistoryReferenceBuilder();
        $completed = $builder->complete($document, 'owner/project');

        self::assertSame($releases, $completed->getReleases());
        self::assertSame($document->getPrefix(), $completed->getPrefix());
        self::assertSame($footer, $document->getReferences());
        self::assertSame("[issue]: https://example.test/42 \r\n"
            . "[1.2.0]: https://github.com/owner/project/compare/v1.1.0...v1.2.0\r\n"
            . "[1.0.0]: https://custom.test/first \"Title\"\r\n"
            . "[1.1.0]: https://github.com/owner/project/compare/v1.0.0...v1.1.0\r\n", $completed->getReferences());
        self::assertSame($completed, $builder->complete($completed, 'owner/project'));
        self::assertStringNotContainsString('[Unreleased]:', $completed->getReferences());
    }

    /** The oldest concrete version points to its release; later identities encode full custom tag names. */
    public function testEncodesCustomPrefixAndBuildMetadataInTagDestinations(): void
    {
        $document = new HistoryDocument([new HistoryRelease('1.0.1+linux'), new HistoryRelease('1.0.0')]);

        self::assertSame("\n[1.0.1+linux]: https://github.com/owner/project/compare/pkg%2F1.0.0...pkg%2F1.0.1%2Blinux\n"
            . "[1.0.0]: https://github.com/owner/project/releases/tag/pkg%2F1.0.0\n",
            new HistoryReferenceBuilder()->complete($document, 'owner/project', 'pkg/')->getReferences());
    }

    /** A consolidated but unpublished version cannot become a new definition or the next release's comparison base. */
    public function testPlannedReleaseComparesAgainstTheLastReachableTag(): void
    {
        $releases = [new HistoryRelease('1.7.2'), new HistoryRelease('1.7.1'), new HistoryRelease('1.7.0')];
        $document = new HistoryDocument($releases, '# Maintained introduction');
        $builder = new HistoryReferenceBuilder();
        $completed = $builder->complete($document, 'owner/project', 'v', ['v1.7.0'], '1.7.2');

        self::assertSame("\n[1.7.2]: https://github.com/owner/project/compare/v1.7.0...v1.7.2\n"
            . "[1.7.0]: https://github.com/owner/project/releases/tag/v1.7.0\n", $completed->getReferences());
        self::assertStringNotContainsString('[1.7.1]:', $completed->getReferences());
        self::assertSame($releases, $completed->getReleases());
        self::assertSame($document->getPrefix(), $completed->getPrefix());
        self::assertSame('', $document->getReferences());
        self::assertSame($completed, $builder->complete($completed, 'owner/project', 'v', ['v1.7.0'], '1.7.2'));
    }

    /** Existing custom definitions remain exact bytes even when their maintained version has no reachable tag. */
    public function testPublishedEvidencePreservesExistingCustomLinksWithoutUsingThemAsTags(): void
    {
        $footer = "[issue]: https://custom.test/42 \r\n"
            . "[1.7.1]: https://custom.test/unpublished \"Retained title\"\r\n"
            . '[1.7.0]: https://custom.test/published';
        $document = new HistoryDocument([
            new HistoryRelease('1.7.2'), new HistoryRelease('1.7.1'), new HistoryRelease('1.7.0'),
        ], references: $footer);
        $builder = new HistoryReferenceBuilder();
        $completed = $builder->complete($document, 'owner/project', 'v', ['v1.7.0'], '1.7.2');

        self::assertSame("[issue]: https://custom.test/42 \r\n"
            . "[1.7.2]: https://github.com/owner/project/compare/v1.7.0...v1.7.2\r\n"
            . "[1.7.1]: https://custom.test/unpublished \"Retained title\"\r\n"
            . '[1.7.0]: https://custom.test/published', $completed->getReferences());
        self::assertSame($footer, $document->getReferences());
        self::assertSame($completed, $builder->complete($completed, 'owner/project', 'v', ['v1.7.0'], '1.7.2'));
    }

    /** The nearest lower reachable tag is a valid predecessor even when its version has no maintained section. */
    public function testComparisonCanUseATagAbsentFromTheDocument(): void
    {
        $document = new HistoryDocument([new HistoryRelease('1.7.2'), new HistoryRelease('1.7.1')]);
        $completed = new HistoryReferenceBuilder()->complete(
            $document,
            'owner/project',
            'v',
            ['v2.0.0', 'v1.6.9', 'v1.7.0'],
            '1.7.2',
        );

        self::assertSame("\n[1.7.2]: https://github.com/owner/project/compare/v1.7.0...v1.7.2\n",
            $completed->getReferences());
        self::assertStringNotContainsString('[1.7.0]:', $completed->getReferences());
        self::assertSame($document->getReleases(), $completed->getReleases());
    }

    /** Tag evidence requires the exact configured prefix and retains complete build identities in encoded destinations. */
    public function testReachableTagsRespectPrefixAndBuildMetadata(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('1.1.0+build.2'), new HistoryRelease('1.0.1'), new HistoryRelease('1.0.0+linux'),
        ]);
        $completed = new HistoryReferenceBuilder()->complete($document, 'owner/project', 'pkg/', [
            'v1.0.1', 'Pkg/1.0.1', 'pkg/1.0.0+linux', 'pkg/1.0.1-rc.1', 'pkg/1.0.1+broken..',
        ], '1.1.0+build.2');

        self::assertSame("\n[1.1.0+build.2]: https://github.com/owner/project/compare/"
            . "pkg%2F1.0.0%2Blinux...pkg%2F1.1.0%2Bbuild.2\n"
            . "[1.0.0+linux]: https://github.com/owner/project/releases/tag/pkg%2F1.0.0%2Blinux\n",
            $completed->getReferences());
        self::assertStringNotContainsString('[1.0.1]:', $completed->getReferences());
    }

    /** An empty configured prefix authorizes bare stable tags, without treating conventionally prefixed tags as equivalent. */
    public function testEmptyPrefixMatchesOnlyBareStableTags(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('1.7.2'), new HistoryRelease('1.7.1'), new HistoryRelease('1.7.0'),
        ]);
        $references = new HistoryReferenceBuilder()->complete(
            $document,
            'owner/project',
            '',
            ['1.7.0', 'v1.7.1'],
            '1.7.2',
        )->getReferences();

        self::assertSame("\n[1.7.2]: https://github.com/owner/project/compare/1.7.0...1.7.2\n"
            . "[1.7.0]: https://github.com/owner/project/releases/tag/1.7.0\n", $references);
    }

    /** Equal-precedence reachable build identities keep the deterministic lexical ordering used by the legacy renderer. */
    #[TestWith([['v1.0.0+a', 'v1.0.0+b']])]
    #[TestWith([['v1.0.0+b', 'v1.0.0+a']])]
    public function testReachableBuildTagsHaveDeterministicPrecedence(array $tags): void
    {
        $document = new HistoryDocument([new HistoryRelease('1.0.1')]);
        $completed = new HistoryReferenceBuilder()->complete($document, 'owner/project', 'v', $tags, '1.0.1');

        self::assertSame("\n[1.0.1]: https://github.com/owner/project/compare/v1.0.0%2Bb...v1.0.1\n",
            $completed->getReferences());
    }

    /** A reachable prerelease, malformed identity or foreign prefix does not authorize a stable-version definition. */
    public function testOnlyStableReachableTagsAuthorizeHistoricalDefinitions(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('2.0.0-rc.1'), new HistoryRelease('1.7.2'), new HistoryRelease('1.7.0'),
        ]);
        $completed = new HistoryReferenceBuilder()->complete($document, 'owner/project', 'v', [
            'v2.0.0-rc.1', 'v1.7.2-rc.1', 'v01.7.2', 'v1.7.2+broken..', 'v1.7.2junk', 'other/1.7.2', 'v1.7.0',
        ]);

        self::assertSame("\n[1.7.0]: https://github.com/owner/project/releases/tag/v1.7.0\n",
            $completed->getReferences());
        self::assertStringNotContainsString('[2.0.0-rc.1]:', $completed->getReferences());
        self::assertStringNotContainsString('[1.7.2]:', $completed->getReferences());
    }

    /** An explicitly empty or unusable tag inventory cannot invent links when there is no planned release. */
    #[TestWith([[]])]
    #[TestWith([['v1.7.1-rc.1', 'v01.7.1', 'v1.7.1+bad..', 'other/1.7.1', 'v']])]
    public function testAbsentStableTagEvidenceLeavesHistoryUnchanged(array $tags): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('1.7.1'), new HistoryRelease('1.7.1-rc.1'), new HistoryRelease('0.0.0'),
        ], references: "[support]: https://custom.test/help\n");

        self::assertSame($document, new HistoryReferenceBuilder()->complete($document, 'owner/project', 'v', $tags));
    }

    /** Tag evidence and a prospective identity cannot add orphaned definitions when no corresponding section exists. */
    #[TestWith([[]])]
    #[TestWith([['v0.0.0', 'v1.0.0']])]
    public function testEmptyHistoryDoesNotGainVersionDefinitions(array $tags): void
    {
        $document = new HistoryDocument(prefix: '# Maintained introduction', references: '[help]: https://custom.test');

        self::assertSame($document, new HistoryReferenceBuilder()->complete(
            $document,
            'owner/project',
            'v',
            $tags,
            '1.0.1',
        ));
    }

    /** The 0.0.0 sentinel becomes a predecessor only when that exact stable tag is actually reachable. */
    #[TestWith([[], '/releases/tag/v0.0.1', false])]
    #[TestWith([['v0.0.0'], '/compare/v0.0.0...v0.0.1', true])]
    public function testInitialPlannedVersionDoesNotInventAZeroTag(array $tags, string $target, bool $zeroTagged): void
    {
        $document = new HistoryDocument([new HistoryRelease('0.0.1'), new HistoryRelease('0.0.0')]);
        $references = new HistoryReferenceBuilder()->complete($document, 'owner/project', 'v', $tags, '0.0.1')
            ->getReferences();

        self::assertStringContainsString('[0.0.1]: https://github.com/owner/project' . $target, $references);
        self::assertSame($zeroTagged, str_contains($references, '[0.0.0]:'));
    }

    /** A null inventory preserves the direct API's legacy behavior, independently of optional planned-version metadata. */
    public function testNullTagInventoryPreservesLegacyCompletion(): void
    {
        $document = new HistoryDocument([new HistoryRelease('1.7.2'), new HistoryRelease('1.7.1')]);
        $builder = new HistoryReferenceBuilder();

        self::assertSame($builder->complete($document, 'owner/project')->getReferences(),
            $builder->complete($document, 'owner/project', 'v', null, '1.7.2')->getReferences());
    }

    /** References use the actual bracketed labels retained in historical headings. */
    public function testPreservesPrefixedHeadingLabelsAndCaseInsensitiveExistingDefinitions(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('1.2.0', heading: "## [v1.2.0] - 2026-10-17\n"),
            new HistoryRelease('1.1.0', heading: "## [V1.1.0]\n"),
        ], references: "[ v1.1.0 ]: https://example.test/retained\n");

        self::assertSame("[v1.2.0]: https://github.com/owner/project/compare/v1.1.0...v1.2.0\n"
            . "[ v1.1.0 ]: https://example.test/retained\n",
            new HistoryReferenceBuilder()->complete($document, 'owner/project')->getReferences());
    }

    /** Existing global definitions remain authoritative even before trailing prose, while code examples are ignored. */
    public function testRecognizesGlobalDefinitionsOutsideFencedAndIndentedCode(): void
    {
        $body = "```markdown\n[1.3.0]: https://example.test/code\n```\n"
            . "~~~\n[1.2.0]: https://example.test/code\n~~~~\n"
            . "    [1.1.0]: https://example.test/indented\n"
            . "[1.0.0]: https://example.test/first\n\nFollowing prose.\n";
        $document = new HistoryDocument([
            new HistoryRelease('1.3.0'), new HistoryRelease('1.2.0'),
            new HistoryRelease('1.1.0'), new HistoryRelease('1.0.0', body: $body),
        ]);
        $completed = new HistoryReferenceBuilder()->complete($document, 'owner/project');

        self::assertSame($body, $completed->getRelease('1.0.0')->getBody());
        self::assertStringNotContainsString('[1.0.0]:', $completed->getReferences());
        foreach (['1.3.0', '1.2.0', '1.1.0'] as $version) {
            self::assertStringContainsString('[' . $version . ']: https://github.com/', $completed->getReferences());
        }
    }

    /** Invalid fence info cannot hide an active definition, while an HTML comment cannot define a link. */
    public function testDefinitionScanningMatchesInvalidBacktickFencesAndHtmlComments(): void
    {
        $body = "```invalid`info\n[v1.0.0]: https://custom.test/active\n\nFollowing prose.\n"
            . "<!-- Hidden reference example\n[1.1.0]: https://example.test/hidden\n-->\n"
            . "<!-- [1.2.0]: https://example.test/hidden-inline -->\n";
        $original = "## [v1.0.0] - 2026-09-01\n\n" . $body;
        $document = $this->codec()->parse($original);
        $document = $document->withReleases([
            new HistoryRelease('1.2.0'), new HistoryRelease('1.1.0'), ...$document->getReleases(),
        ]);
        $completed = new HistoryReferenceBuilder()->complete($document, 'owner/project');

        self::assertSame($document->getReleases(), $completed->getReleases());
        self::assertStringNotContainsString('[v1.0.0]:', $completed->getReferences());
        self::assertStringContainsString(
            '[1.1.0]: https://github.com/owner/project/compare/v1.0.0...v1.1.0',
            $completed->getReferences(),
        );
        self::assertStringContainsString(
            '[1.2.0]: https://github.com/owner/project/compare/v1.1.0...v1.2.0',
            $completed->getReferences(),
        );
    }

    /** Imported sections receive structural newlines during rendering even when their stored bodies lack them. */
    public function testDefinitionsAtNewSectionBoundariesRemainAuthoritative(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('1.1.0', body: 'Fresh prose without a final newline'),
            new HistoryRelease('1.0.0', body: "[1.0.0]: https://custom.test/maintained\n\nMaintained prose."),
        ], '# Introduction without a final newline');
        $completed = new HistoryReferenceBuilder()->complete($document, 'owner/project');
        $template = $this->createStub(TemplateInterface::class);
        $template->method('unreleasedHeading')->willReturn('## [Unreleased]');
        $template->method('releaseHeading')->willReturnCallback(
            static fn(string $version): string => '## [' . $version . ']',
        );

        self::assertStringNotContainsString('[1.0.0]:', $completed->getReferences());
        self::assertSame(1, substr_count($this->codec()->render($completed, $template, true), '[1.0.0]:'));
        self::assertSame($document->getReleases(), $completed->getReleases());
    }

    /** Numeric width and prerelease precedence cannot depend on machine integer ranges or source ordering. */
    public function testSemanticOrderingHandlesPrereleasesAndArbitraryWidthComponents(): void
    {
        $versions = ['1000000000000000000000000000000.0.0', '999999999999999999999999999999.0.0',
            '1.0.0', '1.0.0-rc.10', '1.0.0-rc.2', '1.0.0-beta', '1.0.0-2', '1.0.0-1.1', '1.0.0-1'];
        $document = new HistoryDocument(array_map(
            static fn(string $version): HistoryRelease => new HistoryRelease($version),
            $versions,
        ));
        $references = new HistoryReferenceBuilder()->complete($document, 'owner/project', '')->getReferences();

        foreach (array_slice($versions, 0, -1) as $index => $version) {
            self::assertStringContainsString('[' . $version . ']: https://github.com/owner/project/compare/'
                . $versions[$index + 1] . '...' . $version, $references);
        }
        self::assertStringContainsString(
            '[1.0.0-1]: https://github.com/owner/project/releases/tag/1.0.0-1',
            $references,
        );
    }

    /** Equal SemVer precedence is resolved deterministically without dropping distinct build identities. */
    public function testBuildMetadataTiesAreDeterministic(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('1.0.0+b'),
            new HistoryRelease('1.0.0+a'),
            new HistoryRelease('1.0.0'),
        ]);
        $references = new HistoryReferenceBuilder()->complete($document, 'owner/project')->getReferences();

        self::assertStringContainsString('/compare/v1.0.0%2Ba...v1.0.0%2Bb', $references);
        self::assertStringContainsString('/compare/v1.0.0...v1.0.0%2Ba', $references);
    }

    /** A longer equal-prefix prerelease follows its shorter predecessor in either stored order. */
    #[TestWith([['1.0.0-1', '1.0.0-1.1']])]
    #[TestWith([['1.0.0-1.1', '1.0.0-1']])]
    public function testPrereleaseIdentifierCountDeterminesPrecedence(array $versions): void
    {
        $document = new HistoryDocument(array_map(
            static fn(string $version): HistoryRelease => new HistoryRelease($version),
            $versions,
        ));
        $references = new HistoryReferenceBuilder()->complete($document, 'owner/project')->getReferences();

        self::assertStringContainsString(
            '[1.0.0-1]: https://github.com/owner/project/releases/tag/v1.0.0-1',
            $references,
        );
        self::assertStringContainsString(
            '[1.0.0-1.1]: https://github.com/owner/project/compare/v1.0.0-1...v1.0.0-1.1',
            $references,
        );
    }

    /** Unknown repositories and histories without concrete versions cannot invent destinations. */
    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['https://github.com/owner/project'])]
    public function testUnknownRepositoryPreservesTheDocument(?string $repository): void
    {
        $document = new HistoryDocument([new HistoryRelease('1.0.0')], references: 'Retained');
        self::assertSame($document, new HistoryReferenceBuilder()->complete($document, $repository));
    }

    /** Reserved and malformed identities never become reference labels or tag paths. */
    public function testNonConcreteVersionsLeaveTheDocumentUntouched(): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('unreleased'),
            new HistoryRelease('01.0.0'),
            new HistoryRelease('1.0.0-01'),
        ]);
        self::assertSame($document, new HistoryReferenceBuilder()->complete($document, 'owner/project'));
    }

    /** A new latest reference precedes the old footer without adding a newline to its untouched final definition. */
    public function testRetainsAnUnterminatedFooterAndAddsItsRequiredSeparator(): void
    {
        $footer = '[1.0.0]: https://example.test/first';
        $document = new HistoryDocument([
            new HistoryRelease('1.1.0'),
            new HistoryRelease('1.0.0'),
        ], references: $footer);
        self::assertSame("[1.1.0]: https://github.com/owner/project/compare/v1.0.0...v1.1.0\n" . $footer,
            new HistoryReferenceBuilder()->complete($document, 'owner/project')->getReferences());
    }

    /** New latest, missing middle and oldest references enter the corresponding positions without moving custom links. */
    #[TestWith(["\n", true])]
    #[TestWith(["\r\n", false])]
    public function testInsertsMissingReferencesAmongRetainedVersions(string $newline, bool $terminated): void
    {
        $footer = '[issue]: https://example.test/42' . $newline
            . '[0.6.3]: https://custom.test/63 "Retained title"' . $newline
            . $newline . '[support]: https://example.test/help' . $newline
            . '[0.3.0]: https://custom.test/30' . ($terminated ? $newline : '');
        $document = new HistoryDocument(array_map(
            static fn(string $version): HistoryRelease => new HistoryRelease($version),
            ['0.6.4', '0.6.3', '0.6.2', '0.3.0', '0.1.0'],
        ), references: $footer);
        $builder = new HistoryReferenceBuilder();
        $completed = $builder->complete($document, 'owner/project');

        self::assertSame('[issue]: https://example.test/42' . $newline
            . '[0.6.4]: https://github.com/owner/project/compare/v0.6.3...v0.6.4' . $newline
            . '[0.6.3]: https://custom.test/63 "Retained title"' . $newline
            . $newline . '[support]: https://example.test/help' . $newline
            . '[0.6.2]: https://github.com/owner/project/compare/v0.3.0...v0.6.2' . $newline
            . '[0.3.0]: https://custom.test/30' . $newline
            . '[0.1.0]: https://github.com/owner/project/releases/tag/v0.1.0' . $newline,
            $completed->getReferences());
        self::assertSame($footer, $document->getReferences());
        self::assertSame($completed, $builder->complete($completed, 'owner/project'));
    }

    /** Missing definitions appended after unrelated links still separate an unterminated existing line. */
    public function testAppendsWhenNoRetainedVersionProvidesAnAnchor(): void
    {
        $document = new HistoryDocument([new HistoryRelease('0.1.0')], references: '[help]: https://example.test');

        self::assertSame("[help]: https://example.test\n"
            . "[0.1.0]: https://github.com/owner/project/releases/tag/v0.1.0\n",
            new HistoryReferenceBuilder()->complete($document, 'owner/project')->getReferences());
    }

    /** Repeated real-codec round trips retain historical bytes and exclude footer links from release notes. */
    #[TestWith(["No final newline", ''])]
    #[TestWith(["One newline\n", ''])]
    #[TestWith(["Two newlines\n\n", ''])]
    #[TestWith(["CRLF paragraph\r\n", ''])]
    #[TestWith(["Historical notes\n\n", "[issue]: https://example.test/42\n[v1.0.0]: https://custom.test/v1\n"])]
    #[TestWith(["Historical notes\r\n\r\n", "[issue]: https://example.test/42\r\n[v1.0.0]: https://custom.test/v1"])]
    public function testMarkdownPipelinePreservesHistoricalPresentation(string $body, string $footer): void
    {
        $codec = $this->codec();
        $template = $this->createStub(TemplateInterface::class);
        $template->method('unreleasedHeading')->willReturn('## [Unreleased]');
        $template->method('releaseHeading')->willReturnCallback(
            static fn(string $version, ?string $date): string => '## [' . $version . '] - ' . $date,
        );
        $oldSection = "## [v1.0.0] - 2026-09-01\n\n" . $body;
        $original = "# Maintained introduction\n\n" . $oldSection . $footer;
        $document = $codec->parse($original);
        $promoted = $document->withReleases(
            [new HistoryRelease('1.1.0', '2026-10-17', body: "### Added\n\n- New feature.\n"),
                ...$document->getReleases()],
        );
        $completed = new HistoryReferenceBuilder()->complete($promoted, 'owner/project');
        $rendered = $codec->render($completed, $template, true);
        $parsed = $codec->parse($rendered);

        self::assertStringContainsString($oldSection, $rendered);
        if ('' !== $footer) {
            foreach (preg_split('/\r\n|\n|\r/', rtrim($footer, "\r\n")) as $line) {
                self::assertStringContainsString($line, $rendered);
            }
        }
        self::assertStringContainsString('[v1.0.0]: ', $parsed->getReferences());
        self::assertStringContainsString(
            '[1.1.0]: https://github.com/owner/project/compare/v1.0.0...v1.1.0',
            $parsed->getReferences(),
        );
        self::assertSame($rendered, $codec->render($parsed, $template, true));
        self::assertStringNotContainsString('https://', $codec->notes($parsed, '1.1.0'));
        self::assertStringNotContainsString('[Unreleased]', $rendered);
        self::assertStringNotContainsString('<!-- fast-forward-changelog:', $rendered);
    }

    /** Supplies the real pure codec while keeping value construction and diagnostics injectable. */
    private function codec(): HistoryCodec
    {
        $documents = $this->createStub(HistoryDocumentFactoryInterface::class);
        $documents->method('create')->willReturnCallback(
            static fn(array $releases, string $prefix, string $references): HistoryDocument => new HistoryDocument(
                $releases,
                $prefix,
                $references,
            ),
        );
        $releases = $this->createStub(HistoryReleaseFactoryInterface::class);
        $releases->method('create')->willReturnCallback(
            static fn(string $version, ?string $date, ?string $source, string $body, ?string $heading, string $ending): HistoryRelease
                => new HistoryRelease(preg_replace(
                    '/^[vV](?=\d)/',
                    '',
                    $version,
                ), $date, $source, $body, $heading, $ending),
        );

        return new HistoryCodec($documents, $releases);
    }
}
