<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use DateTimeImmutable;
use DateTimeZone;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryDocumentFactory;
use FastForward\Changelog\History\Factory\HistoryReleaseFactory;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodec;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryReferenceBuilder;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\HistoryImporter;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\History\Import\HistoryImportResult;
use FastForward\Changelog\Markdown\BlockStructure;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeContinueParser;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeStartParser;
use FastForward\Changelog\Markdown\Parser\UnclosedCommentInlineParser;
use FastForward\Changelog\Markdown\SourceDocument;
use FastForward\Changelog\Release\Factory\ReleasePlanFactory;
use FastForward\Changelog\Release\Factory\ReleasePlanFactoryInterface;
use FastForward\Changelog\Release\ReceiptCodec;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseHistoryConsolidator;
use FastForward\Changelog\Release\ReleaseJournalPathResolverInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlanner;
use FastForward\Changelog\Release\ReleaseReceipt;
use FastForward\Changelog\Release\Renderer\ReleaseNotesRenderer;
use FastForward\Changelog\Release\Renderer\ReleaseNotesRendererInterface;
use FastForward\Changelog\Template\Factory\TemplateFactory;
use FastForward\Changelog\Template\KeepAChangelogTemplate;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use FastForward\Changelog\Validator\ReleaseDateValidator;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface;
use FastForward\Changelog\Version\NextVersionResolver;
use FastForward\Changelog\Version\NextVersionResolverInterface;
use FastForward\Changelog\Version\VersionBaselineResolver;
use FastForward\Changelog\Version\VersionImpact;
use FastForward\Changelog\Version\VersionImpactResolver;
use FastForward\Changelog\Version\VersionResolution;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[UsesClass(BlockStructure::class)]
#[UsesClass(TrackedFencedCodeStartParser::class)]
#[UsesClass(TrackedFencedCodeContinueParser::class)]
#[UsesClass(SourceDocument::class)]
#[UsesClass(UnclosedCommentInlineParser::class)]
#[CoversClass(ReleasePlanner::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(VersionBaselineResolver::class)]
#[UsesClass(ReleasePlan::class)]
#[UsesClass(ReleaseHistoryConsolidator::class)]
#[UsesClass(ReleaseReceipt::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryReferenceBuilder::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(HistoryImportResult::class)]
#[UsesClass(ValidationReport::class)]
#[UsesClass(VersionResolution::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(HistoryDocumentFactory::class)]
#[UsesClass(HistoryReleaseFactory::class)]
#[UsesClass(HistoryCodec::class)]
#[UsesClass(HistoryImporter::class)]
#[UsesClass(ReleasePlanFactory::class)]
#[UsesClass(ReceiptCodec::class)]
#[UsesClass(ReleaseNotesRenderer::class)]
#[UsesClass(TemplateFactory::class)]
#[UsesClass(KeepAChangelogTemplate::class)]
#[UsesClass(ReleaseDateValidator::class)]
#[UsesClass(NextVersionResolver::class)]
#[UsesClass(VersionImpactResolver::class)]
#[UsesClass(VersionImpact::class)]
final class ReleasePlannerTest extends TestCase
{
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** PR discovery receives accepted raw hashes and changes presentation without changing authored version inputs. */
    public function testConsolidationAddsOnlyVerifiedPresentationReferences(): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo', resolvePullRequests: true);
        $references = $this->createMock(
            \FastForward\Changelog\Release\Reference\PullRequestReferenceResolverInterface::class,
        );
        $renderer = $this->createMock(ReleaseNotesRendererInterface::class);
        [$planner, $parts] = $this->planner(
            ['fragments' => true, 'pullRequests' => $references, 'renderer' => $renderer],
        );
        $enriched = new Changeset('new.md', Category::Fixed, null, 111, null, 'Description');
        $references->expects(self::once())->method('resolve')->with(
            $options,
            self::SHA,
            self::callback(
                static fn(array $changes): bool => 1 === count($changes) && null === $changes[0]->pullRequest,
            ),
            ['/consumer/.changelog/new.md' => hash('sha256', 'accepted bytes')],
        )->willReturn([$enriched]);
        $renderer->expects(self::once())->method('render')->with(
            [$enriched],
            $parts['template'],
            'owner/repo',
        )->willReturn(
            'Verified reference.',
        );
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);

        self::assertSame($parts['plan'], $planner->plan($options));
    }

    /** Recovery must never silently change its network/reference policy after its exact output was saved. */
    public function testRecoveryRejectsAChangedReferencePolicyBeforeDiscovery(): void
    {
        $receipt = new ReleaseReceipt([...$this->receipt()->data, 'resolve_pull_requests' => true]);
        [$planner, $parts] = $this->planner(['receipt' => $receipt]);
        $parts['plans']->expects(self::never())->method('create');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PR discovery differs');

        $planner->plan(new ReleaseOptions('/consumer'));
    }

    private const string INTRODUCTION = "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\n"
        . "The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),\n"
        . "and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).\n\n";

    /** An absent file creates the first release, or imports actual tags before incrementing their baseline. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testMissingChangelogProducesExactVersionedMarkdown(bool $tagged): void
    {
        $tags = $tagged ? [['name' => 'v1.0.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null]] : [];
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo', source: 'tags');
        $planner = $this->markdownPlanner(null, $tags);
        $plan = $planner->plan($options);
        $expected = $tagged
            ? self::INTRODUCTION . "## [1.0.1] - 2026-10-04\n\n### Fixed\n\n- Fresh fix.\n\n"
                . "## [1.0.0]\n\nRelease notes are unavailable for this version.\n\n"
                . "[1.0.1]: https://github.com/owner/repo/compare/v1.0.0...v1.0.1\n"
                . "[1.0.0]: https://github.com/owner/repo/releases/tag/v1.0.0\n"
            : self::INTRODUCTION . "## [0.0.1] - 2026-10-04\n\n### Fixed\n\n- Fresh fix.\n\n"
                . "[0.0.1]: https://github.com/owner/repo/releases/tag/v0.0.1\n";

        self::assertNull($plan->originalChangelog);
        self::assertSame($tagged ? '1.0.0' : '0.0.0', $plan->currentVersion);
        self::assertSame($tagged ? '1.0.1' : '0.0.1', $plan->nextVersion);
        self::assertSame($tagged ? ['1.0.0'] : [], $plan->historicalVersions);
        self::assertSame($expected, $plan->changelogContents);
        self::assertSame("\n### Fixed\n\n- Fresh fix.\n\n", $plan->notes);
        self::assertSame($expected, $planner->plan($options)->changelogContents);
        self::assertStringNotContainsString('Unreleased', $plan->changelogContents);
    }

    /** Existing sections are authoritative with either history source, including custom reference destinations and CRLF. */
    #[TestWith([false, 'tags'])]
    #[TestWith([true, 'tags'])]
    #[TestWith([false, 'github'])]
    #[TestWith([true, 'github'])]
    public function testIncrementPreservesExactHistoryAndCompletesOnlyMissingFooterLinks(
        bool $linked,
        string $source,
    ): void {
        $prefix = "# Maintained introduction\r\n\r\n";
        $section = "## [v1.0.0] - 2026-09-01\r\n\r\n### Added\r\n\r\n- Existing  spaces.  \r\n\r\n";
        $footer = $linked ? "[v1.0.0]: https://custom.test/first \"Retained title\"\r\n"
            . "[issue]: https://example.test/42\r\n" : '';
        $original = $prefix . $section . $footer;
        $tags = [['name' => 'v1.0.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null]];
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo', source: $source);
        $planner = $this->markdownPlanner($original, $tags);
        $plan = $planner->plan($options);
        $expected = $prefix . "## [1.0.1] - 2026-10-04\n\n### Fixed\n\n- Fresh fix.\n\n" . $section
            . ($linked ? "[1.0.1]: https://github.com/owner/repo/compare/v1.0.0...v1.0.1\r\n" . $footer
                : "[1.0.1]: https://github.com/owner/repo/compare/v1.0.0...v1.0.1\n"
                    . "[v1.0.0]: https://github.com/owner/repo/releases/tag/v1.0.0\n");

        self::assertSame($original, $plan->originalChangelog);
        self::assertSame('1.0.0', $plan->currentVersion);
        self::assertSame('1.0.1', $plan->nextVersion);
        self::assertSame([], $plan->historicalVersions);
        self::assertSame($expected, $plan->changelogContents);
        self::assertSame($expected, $planner->plan($options)->changelogContents);
        self::assertSame($expected, $this->markdownPlanner($expected, $tags, false)->plan($options)->changelogContents);
        self::assertSame(1, substr_count($plan->changelogContents, '[v1.0.0]:'));
        self::assertStringNotContainsString('Unreleased', $plan->changelogContents);
    }

    /** Maintained history establishes version state even while its tag/release publication remains absent. */
    #[TestWith(['tags'])]
    #[TestWith(['github'])]
    #[TestWith(['auto'])]
    public function testExistingStableHistoryWithoutTagsCanBeIncrementedWithoutChangingPriorNotes(string $source): void
    {
        $original = "# Existing history\r\n\r\n## [1.0.0] - 2026-09-01\r\n\r\nOld notes.  \r\n\r\n"
            . "[1.0.0]: https://custom.test/first \"Title\"\r\n";
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo', source: $source);
        $plan = $this->markdownPlanner($original, [], false)->plan($options);

        self::assertSame('1.0.0', $plan->currentVersion);
        self::assertNull($plan->nextVersion);
        self::assertSame($original, $plan->changelogContents);
        self::assertSame([], $plan->consumed);
        self::assertSame([], $plan->historicalVersions);
        $next = $this->markdownPlanner($original, [])->plan($options);

        self::assertSame('1.0.0', $next->currentVersion);
        self::assertSame('1.0.1', $next->nextVersion);
        self::assertStringContainsString(
            "## [1.0.0] - 2026-09-01\r\n\r\nOld notes.  \r\n\r\n",
            $next->changelogContents,
        );
        self::assertStringContainsString('[1.0.0]: https://custom.test/first "Title"', $next->changelogContents);
        self::assertStringContainsString(
            '[1.0.1]: https://github.com/owner/repo/releases/tag/v1.0.1',
            $next->changelogContents,
        );
    }

    /** Delayed publication never duplicates a consolidated version or consumes its prior notes as new fragments. */
    #[TestWith([VersionImpact::Patch, '1.7.2'])]
    #[TestWith([VersionImpact::Minor, '1.8.0'])]
    #[TestWith([VersionImpact::Major, '2.0.0'])]
    public function testNewFragmentsAdvanceBeyondCommittedHistoryWhileTheLastTagLags(
        VersionImpact $impact,
        string $expected,
    ): void {
        $prior = "## [1.7.1] - 2026-10-01\r\n\r\n### Fixed\r\n\r\n- Previously consolidated.  \r\n\r\n"
            . "## [1.7.0] - 2026-09-01\r\n\r\nEarlier published notes.\r\n\r\n";
        $original = "# Maintained history\r\n\r\n" . $prior;
        $tags = [['name' => 'v1.7.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null]];
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo', source: 'tags');
        $plan = $this->markdownPlanner($original, $tags, impact: $impact)->plan($options);

        self::assertSame('1.7.1', $plan->currentVersion);
        self::assertSame($expected, $plan->nextVersion);
        self::assertStringContainsString($prior, $plan->changelogContents);
        self::assertSame(1, substr_count($plan->changelogContents, '- Previously consolidated.'));
        self::assertSame(1, substr_count($plan->changelogContents, '- Fresh fix.'));
        self::assertCount(1, $plan->consumed);
        self::assertSame([], $plan->historicalVersions);

        self::assertStringContainsString(
            '[' . $expected . ']: https://github.com/owner/repo/compare/v1.7.0...v' . $expected,
            $plan->changelogContents,
        );
        self::assertStringNotContainsString('[1.7.1]:', $plan->changelogContents);

        $following = $this->markdownPlanner($plan->changelogContents, $tags)->plan($options);
        $next = new NextVersionResolver(new VersionImpactResolver())->resolve($expected, [
            new Changeset('next.md', Category::Fixed, null, null, null, 'Fresh fix.'),
        ])->nextVersion;

        self::assertSame($expected, $following->currentVersion);
        self::assertSame($next, $following->nextVersion);
        self::assertStringContainsString($prior, $following->changelogContents);
        self::assertStringContainsString(
            '[' . $next . ']: https://github.com/owner/repo/compare/v1.7.0...v' . $next,
            $following->changelogContents,
        );
    }

    /** A legacy pending section is not a published release and can become the first concrete version without any tag. */
    #[TestWith(['tags'])]
    #[TestWith(['github'])]
    public function testLegacyPendingHistoryWithoutTagsBecomesOneConcreteRelease(string $source): void
    {
        $original = "# Existing introduction\n\n## [Unreleased]\n\n### Fixed\n\n- Legacy fix.\n";
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo', source: $source);
        $plan = $this->markdownPlanner($original, [])->plan($options);
        $expected = "# Existing introduction\n\n## [0.0.1] - 2026-10-04\n\n### Fixed\n\n"
            . "- Legacy fix.\n- Fresh fix.\n\n[0.0.1]: https://github.com/owner/repo/releases/tag/v0.0.1\n";

        self::assertSame('0.0.1', $plan->nextVersion);
        self::assertSame($expected, $plan->changelogContents);
        self::assertSame($expected, $this->markdownPlanner($expected, [], false)->plan($options)->changelogContents);
        self::assertSame(1, substr_count($plan->changelogContents, '- Legacy fix.'));
        self::assertStringNotContainsString('Unreleased', $plan->changelogContents);
    }

    /** A concrete release gains deterministic links without changing retained headings, bodies or prior references. */
    public function testVersionPromotionCompletesReleaseReferences(): void
    {
        $older = new HistoryRelease('1.0.0', body: 'Exact prior prose', heading: "## [v1.0.0]\n");
        $document = new HistoryDocument([$older], '# Original', "[issue]: https://example.test/42\r\n");
        [$planner, $parts] = $this->planner(['document' => $document, 'fragments' => true, 'historyMock' => true,
            'tags' => [['name' => 'pkg/1.0.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null]]]);
        $parts['history']->expects(self::once())->method('render')->willReturnCallback(
            static function (HistoryDocument $actual, TemplateInterface $template, bool $preserve) use (
                $older
            ): string {
                self::assertTrue($preserve);
                self::assertSame($older, $actual->getReleases()[1]);
                self::assertSame("[issue]: https://example.test/42\r\n"
                    . "[1.0.1]: https://github.com/owner/repo/compare/pkg%2F1.0.0...pkg%2F1.0.1\r\n"
                    . "[v1.0.0]: https://github.com/owner/repo/releases/tag/pkg%2F1.0.0\r\n", $actual->getReferences());

                return 'Completed history';
            },
        );
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);

        self::assertSame(
            $parts['plan'],
            $planner->plan(new ReleaseOptions('/consumer', tagPrefix: 'pkg/', repository: 'owner/repo')),
        );
        self::assertSame("[issue]: https://example.test/42\r\n", $document->getReferences());
    }

    /** Status and version get the same immutable transaction and exact extracted notes. */
    public function testPlanningCapturesValidatedHashesAndNormalizesTheInjectedClockToUtc(): void
    {
        $unreleased = new HistoryRelease('unreleased', body: 'Legacy pending text');
        $older = new HistoryRelease('1.0.0', body: 'Rich history');
        $document = new HistoryDocument([$unreleased, $older]);
        [$planner, $parts] = $this->planner(
            ['document' => $document, 'fragments' => true, 'releaseMock' => true, 'historyMock' => true],
        );
        $parts['releases']->expects(self::once())->method('create')->with(
            '1.0.1',
            '2026-10-04',
            'release-plan',
            "Legacy pending text\n\nRendered fragments",
            null,
            '',
        )->willReturn(
            new HistoryRelease('1.0.1', body: "Legacy pending text\n\nRendered fragments"),
        );
        $parts['history']->expects(self::once())->method('render')->willReturnCallback(
            static function (HistoryDocument $actual, TemplateInterface $template, bool $preserve): string {
                self::assertTrue($preserve);
                self::assertSame([
                    '1.0.1', '1.0.0'],
                    array_map(
                        static fn(HistoryRelease $release): string => $release->getVersion(),
                        $actual->getReleases(),
                    ),
                );
                self::assertSame("Legacy pending text\n\nRendered fragments", $actual->getReleases()[0]->getBody());
                self::assertSame('Rich history', $actual->getReleases()[1]->getBody());

                return 'after';
            },
        );
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use (
            $parts
        ): ReleasePlan {
            self::assertSame(self::SHA, $arguments[1]);
            self::assertSame('1.0.0', $arguments[2]);
            self::assertSame('1.0.1', $arguments[3]);
            self::assertSame('patch', $arguments[4]);
            self::assertSame(['/consumer/.changelog/new.md' => hash('sha256', 'accepted bytes')], $arguments[5]);
            self::assertSame('after', $arguments[9]);
            self::assertSame("Exact notes\n", $arguments[10]);

            return $parts['plan'];
        });
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Reformatting operates on existing history without reading fragments or fetching releases. */
    public function testFormatDoesNotImportOrConsumeFragments(): void
    {
        [$planner, $parts] = $this->planner(['importerMock' => true, 'validatorMock' => true, 'historyMock' => true]);
        $parts['importer']->expects(self::never())->method('import');
        $parts['validator']->expects(self::never())->method('validate');
        $parts['history']->expects(self::once())->method('render')->with(
            $parts['document'],
            $parts['template'],
            false,
        )->willReturn(
            'translated',
        );
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use (
            $parts
        ): ReleasePlan {
            self::assertNull($arguments[3]);
            self::assertSame([], $arguments[5]);
            self::assertSame('translated', $arguments[9]);

            return $parts['plan'];
        });
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer'), 'format'));
    }

    /** Backfill adds only missing history and cannot turn pending changes into a release. */
    public function testHistoricalOnlyMaintenanceDoesNotResolveSemverOrObserveTheClock(): void
    {
        [$planner, $parts] = $this->planner(
            ['missing' => [
                '0.1.0',
            ], 'validatorMock' => true, 'versionsMock' => true, 'clockMock' => true, 'historyMock' => true],
        );
        $parts['validator']->expects(self::never())->method('validate');
        $parts['versions']->expects(self::never())->method('resolve');
        $parts['clock']->expects(self::never())->method('now');
        $parts['history']->expects(self::once())->method('render')->with(
            $parts['document'],
            $parts['template'],
            true,
        )->willReturn(
            'imported',
        );
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use (
            $parts
        ): ReleasePlan {
            self::assertNull($arguments[3]);
            self::assertSame([], $arguments[5]);
            self::assertSame(['0.1.0'], $arguments[6]);

            return $parts['plan'];
        });
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer'), 'backfill'));
    }

    /** A project with no tags or changes MUST NOT acquire an empty generated document. */
    #[TestWith([null])]
    #[TestWith(['before'])]
    public function testNoChangePreservesExactOriginalBytes(?string $original): void
    {
        [$planner, $parts] = $this->planner(['original' => $original, 'repository' => false, 'historyMock' => true]);
        $parts['history']->expects(self::never())->method('render');
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(function (...$arguments) use (
            $parts,
            $original
        ): ReleasePlan {
            self::assertNull($arguments[1]);
            self::assertSame($original ?? '', $arguments[9]);

            return $parts['plan'];
        });
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** Partial application restores the saved identity instead of calculating a second increment. */
    public function testUnpublishedPlanResumesExactEvidence(): void
    {
        $receipt = $this->receipt();
        [$planner, $parts] = $this->planner(['receipt' => $receipt, 'versionsMock' => true, 'importerMock' => true]);
        $parts['versions']->expects(self::never())->method('resolve');
        $parts['importer']->expects(self::never())->method('import');
        $parts['plans']->expects(self::once())->method('resume')->with(
            self::isInstanceOf(ReleaseOptions::class),
            $receipt,
            '/consumer/CHANGELOG.md',
            'before',
            '/consumer/.git/changelog-release-plan.json',
            'receipt',
        )->willReturn(
            $parts['plan'],
        );
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Published receipt evidence does not prevent the next independent release calculation. */
    public function testPublishedTagCannotDiscardAnInterruptedLocalJournal(): void
    {
        [$planner, $parts] = $this->planner(
            ['receipt' => $this->receipt(), 'tags' => [['name' => 'v1.0.1', 'sha' => self::SHA, 'date' => null, 'date_source' => null]]],
        );
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Unrelated branch tags cannot select the current version or enter historical import. */
    public function testOnlyAncestorTagsReachTheVersionAndImportAuthorities(): void
    {
        $reachable = ['name' => 'v1.0.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null];
        $unrelated = ['name' => 'v9.0.0', 'sha' => str_repeat('9', 40), 'date' => null, 'date_source' => null];
        [$planner, $parts] = $this->planner(['tags' => [$unrelated, $reachable], 'importerMock' => true]);
        $parts['importer']->expects(self::once())->method('currentVersion')->with([$reachable], 'v')->willReturn(
            '1.0.0',
        );
        $parts['importer']->expects(self::once())->method('import')->willReturnCallback(
            static function (HistoryDocument $document, ...$arguments) use ($reachable): HistoryImportResult {
                self::assertContains([$reachable], $arguments);

                return new HistoryImportResult($document, [], '1.0.0');
            },
        );
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** A matching version on another branch does not prove that this saved plan was published. */
    public function testUnrelatedTagCannotMarkAnUnpublishedReceiptAsPublished(): void
    {
        [$planner, $parts] = $this->planner(
            ['receipt' => $this->receipt(), 'tags' => [['name' => 'v1.0.1', 'sha' => str_repeat('9', 40)]]],
        );
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        $parts['plans']->expects(self::never())->method('create');
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** An explicit base MUST identify the inspected checkout, not a different branch. */
    public function testDifferentBaseFailsBeforeConsolidation(): void
    {
        [$planner, $parts] = $this->planner(['baseMismatch' => true]);
        $parts['plans']->expects(self::never())->method('create');
        $this->expectExceptionMessage('Check out the selected base');
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** Executable custom selection must be approved before template resolution can load PHP. */
    public function testTemplateProofRunsBeforeAnyTemplateExecution(): void
    {
        [$planner, $parts] = $this->planner(['inputsMock' => true, 'templatesMock' => true]);
        $options = new ReleaseOptions('/consumer', template: 'presentation.php');
        $parts['inputs']->expects(self::once())->method('validateTemplate')->with(
            $options,
            self::SHA,
        )->willThrowException(
            new RuntimeException('Template differs from the approved base.'),
        );
        $parts['templates']->expects(self::never())->method('resolve');
        $parts['plans']->expects(self::never())->method('create');
        $this->expectExceptionMessage('Template differs from the approved base');
        $planner->plan($options);
    }

    /** Recovery verifies the original template blob without executing its PHP again. */
    public function testReceiptRecoveryDoesNotExecuteCustomTemplateAgain(): void
    {
        $data = $this->receipt()->data;
        $data['template'] = 'presentation.php';
        $receipt = new ReleaseReceipt($data);
        [$planner, $parts] = $this->planner(['receipt' => $receipt, 'inputsMock' => true, 'templatesMock' => true]);
        $options = new ReleaseOptions('/consumer', template: 'presentation.php');
        $parts['inputs']->expects(self::once())->method('validateTemplate')->with($options, self::SHA);
        $parts['templates']->expects(self::never())->method('resolve');
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan($options));
    }

    /** A descendant checkout cannot silently replace the template used by an interrupted release. */
    public function testRecoveryPinsCustomTemplateToReceiptBaseRatherThanCurrentHead(): void
    {
        $data = $this->receipt()->data;
        $data['template'] = 'presentation.php';
        $data['base_sha'] = str_repeat('f', 40);
        [$planner, $parts] = $this->planner(
            ['receipt' => new ReleaseReceipt($data), 'inputsMock' => true, 'templatesMock' => true],
        );
        $options = new ReleaseOptions('/consumer', template: 'presentation.php');
        $parts['inputs']->expects(self::once())->method('validateTemplate')->with(
            $options,
            str_repeat('f', 40),
        )->willThrowException(
            new RuntimeException('Custom template differs from the approved base.'),
        );
        $parts['templates']->expects(self::never())->method('resolve');
        $parts['plans']->expects(self::never())->method('resume');
        $this->expectExceptionMessage('Custom template differs from the approved base');
        $planner->plan($options);
    }

    /** Malformed or incompletely evidenced inventory is rejected before applying anything. */
    #[TestWith(['invalid'])]
    #[TestWith(['hashless'])]
    #[TestWith(['semver'])]
    #[TestWith(['duplicate'])]
    public function testReleasePlanningFailuresAreDiagnostic(string $failure): void
    {
        $document = 'duplicate' === $failure ? new HistoryDocument([
            new HistoryRelease('1.0.1'),
        ]) : new HistoryDocument();
        [$planner, $parts] = $this->planner(
            ['fragments' => true, 'failure' => $failure, 'document' => $document, 'currentVersion' => 'duplicate' === $failure ? '1.0.1' : '1.0.0'],
        );
        $parts['plans']->expects(self::never())->method('create');
        $this->expectException(
            'duplicate' === $failure || 'hashless' === $failure ? RuntimeException::class : InvalidArgumentException::class,
        );
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** A fresh checkout uses the committed stable section before applying the next explicit fragment impact. */
    #[TestWith(['1.1.0', 'minor'])]
    #[TestWith(['2.0.0', 'major'])]
    public function testFreshCheckoutUsesMaintainedVersionWhilePublicationIsPending(
        string $next,
        string $impact,
    ): void {
        $document = new HistoryDocument([new HistoryRelease('1.0.1'), new HistoryRelease('1.0.0')]);
        [$planner, $parts] = $this->planner(['document' => $document, 'fragments' => true, 'nextVersion' => $next,
            'impact' => VersionImpact::from($impact), 'versionsMock' => true, 'clockMock' => true]);
        $parts['versions']->expects(self::once())->method('resolve')->with('1.0.1', self::isArray())
            ->willReturn(new VersionResolution($next, VersionImpact::from($impact), []));
        $parts['clock']->expects(self::once())->method('now')->willReturn(new DateTimeImmutable('2026-10-04'));
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(static function (...$arguments) use (
            $parts,
            $next,
        ): ReleasePlan {
            self::assertSame('1.0.1', $arguments[2]);
            self::assertSame($next, $arguments[3]);

            return $parts['plan'];
        });

        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** All stable sections participate regardless of presentation order, a conventional prefix or import position. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testMaintainedSectionOutsideFirstPositionEstablishesTheBaseline(bool $imported): void
    {
        $document = new HistoryDocument([
            new HistoryRelease('unreleased'),
            new HistoryRelease('0.8.0'),
            new HistoryRelease('1.0.0'),
        ]);
        $maintained = new HistoryDocument([
            new HistoryRelease('unreleased'),
            new HistoryRelease('0.8.0'),
            new HistoryRelease('v1.0.1+build.5'),
            new HistoryRelease('1.0.0'),
        ]);
        [$planner, $parts] = $this->planner(['document' => $imported ? $document : $maintained,
            'importedDocument' => $maintained, 'fragments' => true, 'versionsMock' => true]);
        $parts['versions']->expects(self::once())->method('resolve')->with('1.0.1+build.5', self::isArray())
            ->willReturn(new VersionResolution('1.0.2', VersionImpact::Patch, []));
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);

        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer', template: 'custom.php')));
    }

    /** A maintained stable identity supplies version state without claiming publication or creating a tag. */
    public function testPreexistingStableHistoryWithNoPublishedBaselineIsNotImplicitlyPublished(): void
    {
        [$planner, $parts] = $this->planner(['document' => new HistoryDocument([new HistoryRelease('0.1.0')]),
            'currentVersion' => '0.0.0', 'tags' => [], 'fragments' => true, 'versionsMock' => true]);
        $parts['versions']->expects(self::once())->method('resolve')->with('0.1.0', self::isArray())
            ->willReturn(new VersionResolution('0.1.1', VersionImpact::Patch, []));
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);

        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** An untagged zero section remains valid version state while unrelated tags stay excluded. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testZeroMaintainedReleaseDoesNotRequirePublication(bool $unrelatedTag): void
    {
        $tags = $unrelatedTag ? [['name' => 'v0.0.0', 'sha' => str_repeat(
            '9',
            40,
        ), 'date' => null, 'date_source' => null]] : [];
        [$planner, $parts] = $this->planner(['document' => new HistoryDocument([new HistoryRelease('0.0.0')]),
            'currentVersion' => '0.0.0', 'tags' => $tags, 'fragments' => true, 'nextVersion' => '0.0.1',
            'versionsMock' => true, 'clockMock' => true]);
        $parts['versions']->expects(self::once())->method('resolve')->with('0.0.0', self::isArray())
            ->willReturn(new VersionResolution('0.0.1', VersionImpact::Patch, []));
        $parts['clock']->expects(self::once())->method('now')->willReturn(new DateTimeImmutable('2026-10-04'));
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);

        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** An actual published zero tag and genuinely empty initial history both permit the first patch. */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testActualZeroTagAndEmptyInitialHistoryAllowPatchPlanning(bool $tagged): void
    {
        $document = new HistoryDocument($tagged ? [new HistoryRelease('0.0.0')] : []);
        $tags = $tagged ? [['name' => 'v0.0.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null]] : [];
        [$planner, $parts] = $this->planner(['document' => $document, 'currentVersion' => '0.0.0',
            'tags' => $tags, 'fragments' => true, 'nextVersion' => '0.0.1', 'versionsMock' => true]);
        $parts['versions']->expects(self::once())->method('resolve')->with(
            '0.0.0',
            self::callback(static fn(mixed $changes): bool => is_array($changes) && 1 === count($changes)),
        )
            ->willReturn(new VersionResolution('0.0.1', VersionImpact::Patch, []));
        $parts['plans']->expects(self::once())->method('create')->willReturnCallback(static function (...$arguments) use (
            $parts
        ): ReleasePlan {
            self::assertSame('0.0.0', $arguments[2]);
            self::assertSame('0.0.1', $arguments[3]);

            return $parts['plan'];
        });
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Numeric precedence remains exact for components wider than platform integers. */
    public function testPendingHistoryUsesArbitraryWidthSemanticComponents(): void
    {
        $current = '999999999999999999999999999999.4.0';
        $pending = '1000000000000000000000000000000.0.0';
        [$planner, $parts] = $this->planner(['document' => new HistoryDocument([new HistoryRelease($pending)]),
            'currentVersion' => $current, 'fragments' => true, 'versionsMock' => true]);
        $parts['versions']->expects(self::once())->method('resolve')->with($pending, self::isArray())
            ->willReturn(new VersionResolution('1000000000000000000000000000000.0.1', VersionImpact::Patch, []));
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);

        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Tagged baseline, older releases, build precedence and prerelease/unreleased sections do not block a new version. */
    public function testPublishedAndNonStableHistoryAllowIndependentNextVersion(): void
    {
        $document = new HistoryDocument([new HistoryRelease('unreleased'), new HistoryRelease('2.0.0-rc.1'),
            new HistoryRelease('0.999.999'), new HistoryRelease('1.9.999'), new HistoryRelease('1.10.0+other.build')]);
        [$planner, $parts] = $this->planner(['document' => $document, 'currentVersion' => '1.10.0+published.build',
            'fragments' => true, 'nextVersion' => '1.10.1', 'versionsMock' => true]);
        $parts['versions']->expects(self::once())->method('resolve')->with(
            '1.10.0+published.build',
            self::callback(static fn(mixed $changes): bool => is_array($changes) && 1 === count($changes)),
        )
            ->willReturn(new VersionResolution('1.10.1', VersionImpact::Patch, []));
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** An observed publication unlocks the next impact rather than rewriting the existing maintained release. */
    public function testReachableTagForPendingMaintainedSectionUnlocksNextVersion(): void
    {
        [$planner, $parts] = $this->planner(
            ['document' => new HistoryDocument([new HistoryRelease('1.0.1'), new HistoryRelease('1.0.0')]),
                'currentVersion' => '1.0.1', 'tags' => [['name' => 'v1.0.1', 'sha' => self::SHA, 'date' => null, 'date_source' => null]],
                'fragments' => true, 'nextVersion' => '1.1.0', 'versionsMock' => true],
        );
        $parts['versions']->expects(self::once())->method('resolve')->with(
            '1.0.1',
            self::callback(static fn(mixed $changes): bool => is_array($changes) && 1 === count($changes)),
        )
            ->willReturn(new VersionResolution('1.1.0', VersionImpact::Minor, []));
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** An unrelated tag is excluded even when approved history independently establishes the same baseline. */
    public function testUnrelatedMatchingTagCannotEstablishTheMaintainedBaseline(): void
    {
        $reachable = ['name' => 'v1.0.0', 'sha' => self::SHA, 'date' => null, 'date_source' => null];
        $unrelated = ['name' => 'v1.0.1', 'sha' => str_repeat('9', 40), 'date' => null, 'date_source' => null];
        $document = new HistoryDocument([new HistoryRelease('1.0.1'), new HistoryRelease('1.0.0')]);
        [$planner, $parts] = $this->planner(['document' => $document, 'tags' => [$unrelated, $reachable],
            'fragments' => true, 'importerMock' => true, 'versionsMock' => true]);
        $parts['importer']->expects(self::once())->method('currentVersion')->with([$reachable], 'v')->willReturn(
            '1.0.0',
        );
        $parts['versions']->expects(self::once())->method('resolve')->with('1.0.1', self::isArray())
            ->willReturn(new VersionResolution('1.0.2', VersionImpact::Patch, []));
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);

        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Pending publication never blocks history maintenance or invents a new version when there are no fragments. */
    #[TestWith(['version'])]
    #[TestWith(['format'])]
    #[TestWith(['backfill'])]
    public function testPendingStableHistoryDoesNotBlockNonVersionWork(string $operation): void
    {
        [$planner, $parts] = $this->planner(
            ['document' => new HistoryDocument([new HistoryRelease('1.0.1')]), 'versionsMock' => true],
        );
        $parts['versions']->expects(self::never())->method('resolve');
        $parts['plans']->expects(self::once())->method('create')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer'), $operation));
    }

    /** Saved plans cannot consume later fragments or silently change presentation settings. */
    #[TestWith(['settings'])]
    #[TestWith(['document'])]
    #[TestWith(['notes'])]
    #[TestWith(['new'])]
    #[TestWith(['changed'])]
    #[TestWith(['absent'])]
    #[TestWith(['maintenance'])]
    public function testInconsistentPendingPlansFailClosed(string $failure): void
    {
        $receipt = $this->receipt();
        $data = $receipt->data;
        if ('settings' === $failure) {
            $data['locale'] = 'pt-BR';
        } elseif ('document' === $failure) {
            $data['after_changelog_sha256'] = hash('sha256', 'changed');
        } elseif ('notes' === $failure) {
            $data['notes_sha256'] = hash('sha256', 'changed');
        } elseif ('changed' === $failure) {
            $data['consumed']['.changelog/new.md'] = hash('sha256', 'older');
        }
        [$planner, $parts] = $this->planner(
            ['receipt' => new ReleaseReceipt($data), 'fragments' => in_array(
                $failure,
                ['new', 'changed'],
                true,
            ), 'original' => 'absent' === $failure ? null : 'before'],
        );
        $parts['plans']->expects(self::never())->method('resume');
        $this->expectException(RuntimeException::class);
        $planner->plan(new ReleaseOptions('/consumer'), 'maintenance' === $failure ? 'format' : 'version');
    }

    /** A valid remaining consumed fragment is recoverable after sibling removal. */
    public function testRemainingApprovedFragmentCanResume(): void
    {
        $data = $this->receipt()->data;
        $data['consumed']['.changelog/new.md'] = hash('sha256', 'accepted bytes');
        [$planner, $parts] = $this->planner(['receipt' => new ReleaseReceipt($data), 'fragments' => true]);
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** A saved journal survives failure before writing a previously absent central document. */
    #[TestWith([null])]
    #[TestWith(['original'])]
    public function testPreparedJournalRestoresTheSavedOutput(?string $original): void
    {
        $data = $this->receipt()->data;
        $data['before_changelog_sha256'] = null === $original ? null : hash('sha256', $original);
        $data['consumed'] = ['.changelog/new.md' => hash('sha256', 'accepted bytes')];
        $receipt = new ReleaseReceipt($data);
        [$planner, $parts] = $this->planner(
            ['receipt' => $receipt, 'original' => $original, 'fragments' => true, 'clockMock' => true],
        );
        $parts['clock']->expects(self::never())->method('now');
        $parts['plans']->expects(self::once())->method('resume')->with(
            self::isInstanceOf(ReleaseOptions::class),
            $receipt,
            '/consumer/CHANGELOG.md',
            $original,
            '/consumer/.git/changelog-release-plan.json',
            'receipt',
        )->willReturn(
            $parts['plan'],
        );
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer')));
    }

    /** Missing approved input before the central write prevents an incomplete recovered release. */
    public function testPreparedJournalRequiresAllConsumedFragments(): void
    {
        $data = $this->receipt()->data;
        $data['consumed'] = ['.changelog/new.md' => hash('sha256', 'accepted bytes')];
        [$planner, $parts] = $this->planner(['receipt' => new ReleaseReceipt($data), 'original' => 'original']);
        $parts['plans']->expects(self::never())->method('resume');
        $this->expectExceptionMessage('All approved fragments must remain available');
        $planner->plan(new ReleaseOptions('/consumer'));
    }

    /** A prepared maintenance journal cannot inspect, reject or consume pending fragments. */
    #[TestWith(['version'])]
    #[TestWith(['backfill'])]
    #[TestWith(['format'])]
    public function testPreparedMaintenanceRecoversWithoutInspectingPendingInventory(string $operation): void
    {
        $data = $this->receipt()->data;
        $data['next_version'] = null;
        $data['notes'] = '';
        $data['notes_sha256'] = hash('sha256', '');
        [$planner, $parts] = $this->planner(
            ['receipt' => new ReleaseReceipt(
                $data,
            ), 'original' => 'original', 'failure' => 'invalid', 'fragments' => true, 'validatorMock' => true],
        );
        $parts['validator']->expects(self::never())->method('validate');
        $parts['plans']->expects(self::once())->method('resume')->willReturn($parts['plan']);
        self::assertSame($parts['plan'], $planner->plan(new ReleaseOptions('/consumer'), $operation));
    }

    /** Invalid operation names cannot enter filesystem or Git boundaries. */
    public function testUnknownOperationIsRejected(): void
    {
        [$planner, $parts] = $this->planner();
        $parts['files']->expects(self::never())->method('read');
        $parts['plans']->expects(self::never())->method('create');
        $this->expectException(InvalidArgumentException::class);
        $planner->plan(new ReleaseOptions('/consumer'), 'guess');
    }

    /** Exercises the real pure Markdown/version pipeline with synthetic Git, filesystem, network and clock boundaries. */
    private function markdownPlanner(
        ?string $original,
        array $tags,
        bool $pending = true,
        VersionImpact $impact = VersionImpact::Patch,
    ): ReleasePlanner {
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturnCallback(static fn(string $path): string => '/consumer/' . $path);
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $files->method('read')->willReturnMap([
            ['/consumer/CHANGELOG.md', $original],
            ['/consumer/.git/changelog-release-plan.json', null],
        ]);
        $files->expects(self::never())->method('write');
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('isRepository')->willReturn(true);
        $git->method('resolveRef')->willReturn(self::SHA);
        $git->method('tags')->willReturn($tags);
        $git->method('isAncestor')->willReturn(true);
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::never())->method('request');
        $github->expects(self::never())->method('paginate');
        $documents = new HistoryDocumentFactory();
        $releases = new HistoryReleaseFactory(new ReleaseDateValidator());
        $history = new HistoryCodec($documents, $releases);
        $template = new TemplateFactory()->create();
        $templates = $this->createStub(TemplateResolverInterface::class);
        $templates->method('resolve')->willReturn($template);
        $validator = $this->createStub(ChangesetValidatorInterface::class);
        $validator->method('validate')->willReturn(new ValidationReport(
            $pending ? [new Changeset('fix.md', Category::Fixed, null, null, null, 'Fresh fix.', $impact)] : [],
            [],
            false,
            $pending ? ['/consumer/.changelog/fix.md' => hash('sha256', 'accepted fragment')] : [],
        ));
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-03T23:30:00-03:00'));
        $journals = $this->createStub(ReleaseJournalPathResolverInterface::class);
        $journals->method('resolve')->willReturn('/consumer/.git/changelog-release-plan.json');
        $receipts = new ReceiptCodec();

        return new ReleasePlanner(
            $paths,
            $files,
            $git,
            $history,
            new HistoryImporter($github, $documents, $releases),
            $templates,
            $validator,
            new NextVersionResolver(new VersionImpactResolver()),
            new ReleaseNotesRenderer(),
            new ReleaseHistoryConsolidator($releases),
            $clock,
            new DateTimeZone('UTC'),
            $receipts,
            new ReleasePlanFactory($receipts),
            $this->createStub(ReleaseInputEvidenceValidatorInterface::class),
            $journals,
            $this->createStub(\FastForward\Changelog\Release\Reference\PullRequestReferenceResolverInterface::class),
        );
    }

    /** Builds explicit saved evidence for recovery-policy unit tests. */
    private function receipt(): ReleaseReceipt
    {
        return new ReleaseReceipt(['base_sha' => self::SHA, 'next_version' => '1.0.1', 'changelog_file' => 'CHANGELOG.md',
            'fragment_directory' => '.changelog', 'locale' => 'en', 'template' => 'keep-a-changelog',
            'tag_prefix' => 'v', 'repository' => null, 'consumed' => [],
            'before_changelog_sha256' => hash(
                'sha256',
                'original',
            ), 'changelog_contents' => 'before', 'after_changelog_sha256' => hash(
                'sha256',
                'before',
            ), 'notes' => "Exact notes\n", 'notes_sha256' => hash(
                'sha256',
                "Exact notes\n",
            )]);
    }

    /** All external collaborators are doubles; clock/date values are fixed input data. */
    private function planner(array $settings = []): array
    {
        $options = new ReleaseOptions('/consumer');
        $document = $settings['document'] ?? new HistoryDocument();
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturnCallback(
            static fn(string $category): string => '### ' . ucfirst($category),
        );
        $plan = new ReleasePlan(
            $options,
            'id',
            self::SHA,
            '1.0.0',
            null,
            null,
            [],
            [],
            '/consumer/CHANGELOG.md',
            'before',
            'after',
            '',
            '/consumer/.git/changelog-release-plan.json',
            null,
            'receipt',
        );
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturnCallback(static fn(string $path): string => '/consumer/' . $path);
        $paths->method('relativePath')->willReturnCallback(
            static fn(string $path): string => substr($path, strlen('/consumer/')),
        );
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $original = array_key_exists('original', $settings) ? $settings['original'] : 'before';
        $files->method('read')->willReturnMap(
            [['/consumer/CHANGELOG.md', $original], [
                '/consumer/.git/changelog-release-plan.json',
                isset($settings['receipt']) ? 'receipt' : null,
            ]],
        );
        $files->expects(self::never())->method('write');
        $journals = $this->createStub(ReleaseJournalPathResolverInterface::class);
        $journals->method('resolve')->willReturn('/consumer/.git/changelog-release-plan.json');
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('isRepository')->willReturn($settings['repository'] ?? true);
        $git->method('resolveRef')->willReturnOnConsecutiveCalls(
            self::SHA,
            ($settings['baseMismatch'] ?? false) ? str_repeat('b', 40) : self::SHA,
        );
        $git->method('tags')->willReturn(
            $settings['tags'] ?? [['name' => 'v' . ($settings['currentVersion'] ?? '1.0.0'), 'sha' => self::SHA, 'date' => null, 'date_source' => null]],
        );
        $git->method('isAncestor')->willReturnCallback(
            static fn(string $directory, string $ancestor): bool => $ancestor !== str_repeat('9', 40),
        );
        $history = ($settings['historyMock'] ?? false) ? $this->createMock(
            HistoryCodecInterface::class,
        ) : $this->createStub(
            HistoryCodecInterface::class,
        );
        $history->method('parse')->willReturn($document);
        $history->method('notes')->willReturn("Exact notes\n");
        $importer = ($settings['importerMock'] ?? false) ? $this->createMock(
            HistoryImporterInterface::class,
        ) : $this->createStub(
            HistoryImporterInterface::class,
        );
        $importer->method('currentVersion')->willReturn($settings['currentVersion'] ?? '1.0.0');
        $importer->method('import')->willReturn(
            new HistoryImportResult(
                $settings['importedDocument'] ?? $document,
                $settings['missing'] ?? [],
                $settings['currentVersion'] ?? '1.0.0',
            ),
        );
        $templates = ($settings['templatesMock'] ?? false) ? $this->createMock(
            TemplateResolverInterface::class,
        ) : $this->createStub(
            TemplateResolverInterface::class,
        );
        $templates->method('resolve')->willReturn($template);
        $change = new Changeset('new.md', Category::Fixed, null, null, null, 'Description');
        $fragments = ($settings['fragments'] ?? false) ? [$change] : [];
        $hashes = [] === $fragments || 'hashless' === ($settings['failure'] ?? '') ? [] : ['/consumer/.changelog/new.md' => hash(
            'sha256',
            'accepted bytes',
        )];
        $validator = ($settings['validatorMock'] ?? false) ? $this->createMock(
            ChangesetValidatorInterface::class,
        ) : $this->createStub(
            ChangesetValidatorInterface::class,
        );
        $validator->method('validate')->willReturn(
            new ValidationReport($fragments, 'invalid' === ($settings['failure'] ?? '') ? ['new.md' => [
                'bad category',
            ]] : [], false, $hashes),
        );
        $versions = ($settings['versionsMock'] ?? false) ? $this->createMock(
            NextVersionResolverInterface::class,
        ) : $this->createStub(
            NextVersionResolverInterface::class,
        );
        if (!($settings['versionsMock'] ?? false)) {
            $versions->method('resolve')->willReturn(
                'semver' === ($settings['failure'] ?? '') ? new VersionResolution(null, null, [
                    'invalid tag',
                ]) : new VersionResolution(
                    $settings['nextVersion'] ?? '1.0.1',
                    $settings['impact'] ?? VersionImpact::Patch,
                    [],
                ),
            );
        }
        $renderer = $settings['renderer'] ?? $this->createStub(ReleaseNotesRendererInterface::class);
        if (!isset($settings['renderer'])) {
            $renderer->method('render')->willReturn('Rendered fragments');
        }
        $releases = ($settings['releaseMock'] ?? false) ? $this->createMock(
            HistoryReleaseFactoryInterface::class,
        ) : $this->createStub(
            HistoryReleaseFactoryInterface::class,
        );
        if (! ($settings['releaseMock'] ?? false)) {
            $releases->method('create')->willReturnCallback(
                static fn(string $version, ?string $date, ?string $source, string $body): HistoryRelease => new HistoryRelease(
                    $version,
                    $date,
                    $source,
                    $body,
                ),
            );
        }
        $clock = ($settings['clockMock'] ?? false) ? $this->createMock(ClockInterface::class) : $this->createStub(
            ClockInterface::class,
        );
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-03T23:30:00-03:00'));
        $receipts = $this->createStub(ReceiptCodecInterface::class);
        $receipts->method('decode')->willReturn($settings['receipt'] ?? $this->receipt());
        $plans = $this->createMock(ReleasePlanFactoryInterface::class);
        $inputs = ($settings['inputsMock'] ?? false) ? $this->createMock(
            ReleaseInputEvidenceValidatorInterface::class,
        ) : $this->createStub(
            ReleaseInputEvidenceValidatorInterface::class,
        );

        return [new ReleasePlanner(
            $paths,
            $files,
            $git,
            $history,
            $importer,
            $templates,
            $validator,
            $versions,
            $renderer,
            new ReleaseHistoryConsolidator($releases),
            $clock,
            new DateTimeZone('UTC'),
            $receipts,
            $plans,
            $inputs,
            $journals,
            $settings['pullRequests'] ?? $this->createStub(
                \FastForward\Changelog\Release\Reference\PullRequestReferenceResolverInterface::class,
            ),
        ),
            compact(
                'document',
                'template',
                'plan',
                'files',
                'git',
                'history',
                'importer',
                'validator',
                'versions',
                'releases',
                'clock',
                'plans',
                'inputs',
                'templates',
            )];
    }
}
