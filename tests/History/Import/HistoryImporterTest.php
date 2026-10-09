<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History\Import;

use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryExceptionFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\Factory\HistoryImportResultFactoryInterface;
use FastForward\Changelog\History\Import\HistoryImporter;
use FastForward\Changelog\History\Import\HistoryImportResult;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\TemplateInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(HistoryImporter::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(HistoryImportResult::class)]
#[UsesClass(ReleaseOptions::class)]
final class HistoryImporterTest extends TestCase
{
    #[Test]
    public function formattingBaselineUsesTheSharedTagPolicyWithoutFetchingReleases(): void
    {
        $importer = $this->importer();
        self::assertSame('0.0.0', $importer->currentVersion([]));
        self::assertSame(
            '2.0.0',
            $importer->currentVersion([
                $this->tag('release/1.0.0'),
                $this->tag('release/2.0.0'),
                $this->tag('v9.0.0'),
                $this->tag('release/3.0.0-rc.1'),
            ], 'release/'),
        );
    }

    #[Test]
    public function emptyGitEvidenceKeepsHistoryAndBaselineZeroWithoutNetwork(): void
    {
        $document = new HistoryDocument([new HistoryRelease('99.0.0')], 'prefix', 'footer');
        $result = $this->importer()->import($document, new ReleaseOptions('/consumer'), $this->template(), []);
        self::assertSame($document, $result->document);
        self::assertSame([], $result->missingVersions);
        self::assertSame('0.0.0', $result->currentVersion);
    }

    #[Test]
    #[TestWith(['tags'])]
    #[TestWith(['auto'])]
    public function importsOnlyStrictStablePrefixMatchingTagsWithKnownTagDate(string $source): void
    {
        $tags = [$this->tag('v1.2.3', '2020-01-01', 'annotated-tag'), $this->tag('v1.10.0', '1999-01-01', 'commit'),
            $this->tag('v2.0.0-beta.1'), $this->tag('other3.0.0'), $this->tag('v01.0.0'), $this->tag('vv1.0.0')];
        $result = $this->importer()->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', source: $source),
            $this->template(),
            $tags,
        );
        self::assertSame('1.10.0', $result->currentVersion);
        self::assertSame(['1.10.0', '1.2.3'], $result->missingVersions);
        self::assertNull($result->document->getRelease('1.10.0')->getDate());
        self::assertNull($result->document->getRelease('1.10.0')->getDateSource());
        self::assertSame('2020-01-01', $result->document->getRelease('1.2.3')->getDate());
        self::assertSame('annotated-tag', $result->document->getRelease('1.2.3')->getDateSource());
        self::assertSame("No notes available.\n", $result->document->getRelease('1.2.3')->getBody());
    }

    #[Test]
    public function retainsExistingSectionObjectsOrderDescriptionsMetadataAndFooter(): void
    {
        $pending = new HistoryRelease('unreleased', null, null, 'pending', 'heading');
        $old = new HistoryRelease(
            '1.0.0',
            '2020-01-01',
            'manual',
            'original raw notes',
            'original heading',
            'original ending',
        );
        $document = new HistoryDocument([$pending, $old], 'raw prefix', 'raw refs and footer');
        $result = $this->importer()->import(
            $document,
            new ReleaseOptions('/consumer', source: 'tags'),
            $this->template(),
            [$this->tag('v1.0.0'), $this->tag('v2.0.0'), $this->tag('v0.5.0')],
        );
        self::assertSame(
            [$pending, $result->document->getRelease('2.0.0'), $old, $result->document->getRelease('0.5.0')],
            $result->document->getReleases(),
        );
        self::assertSame(['2.0.0', '0.5.0'], $result->missingVersions);
        self::assertSame('raw prefix', $result->document->getPrefix());
        self::assertSame('raw refs and footer', $result->document->getReferences());
        self::assertSame('original raw notes', $old->getBody());
    }

    #[Test]
    public function stableComparisonSupportsLargeNumericComponentsBuildMetadataAndEmptyPrefix(): void
    {
        $tags = [$this->tag('1.0.0+build.a'), $this->tag('1.0.0+build.b'), $this->tag('18446744073709551615.0.0'),
            $this->tag('18446744073709551616.0.0'), $this->tag('2.0.0'), $this->tag('2.1.0'), $this->tag(
                '2.1.1',
            ), $this->tag(
                'v99.0.0',
            )];
        $result = $this->importer()->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', tagPrefix: '', source: 'tags'),
            $this->template(),
            $tags,
        );
        self::assertSame('18446744073709551616.0.0', $result->currentVersion);
        self::assertSame(
            [
                '18446744073709551616.0.0',
                '18446744073709551615.0.0',
                '2.1.1',
                '2.1.0',
                '2.0.0',
                '1.0.0+build.b',
                '1.0.0+build.a',
            ],
            $result->missingVersions,
        );
    }

    #[Test]
    public function publishedNotesAndUtcPublicationDateTakePriorityOnlyForActualGitTags(): void
    {
        $raw = "Raw prose and [link](https://example.test).\n\n## [99.0.0]\nDescription heading, not another release.\n```md\n### Added\n```\n";
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('paginate')->with('/repos/owner/project/releases')->willReturn([
            $this->release('v1.0.0', $raw, '2026-10-03T00:30:00+01:00'),
            $this->release('v9.0.0', 'No matching tag', 'invalid'),
            $this->release('v2.0.0', 'draft', 'invalid', true),
            $this->release('v3.0.0', 'prerelease', 'invalid', false, true),
        ]);
        $result = $this->importer($github)->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            $this->template(),
            [$this->tag('v1.0.0', '2020-01-01', 'annotated-tag'), $this->tag('v2.0.0'), $this->tag('v3.0.0')],
        );
        self::assertSame('3.0.0', $result->currentVersion);
        self::assertSame($raw, $result->document->getRelease('1.0.0')->getBody());
        self::assertSame('2026-10-02', $result->document->getRelease('1.0.0')->getDate());
        self::assertSame('github-release', $result->document->getRelease('1.0.0')->getDateSource());
        self::assertSame("No notes available.\n", $result->document->getRelease('2.0.0')->getBody());
        self::assertNull($result->document->getRelease('9.0.0'));
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith([" \n\t"])]
    public function missingGithubNotesUseTemplateTextAndKeepObservedPublicationDate(?string $body): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('paginate')->willReturn(
            [$this->release('v1.0.0', $body, '2026-10-03T02:30:00.123Z')],
        );
        $result = $this->importer($github)->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project', source: 'github'),
            $this->template(),
            [$this->tag('v1.0.0')],
        );
        self::assertSame("No notes available.\n", $result->document->getRelease('1.0.0')->getBody());
        self::assertSame('2026-10-03', $result->document->getRelease('1.0.0')->getDate());
    }

    #[Test]
    public function alreadyPresentTagsDoNotFetchOrRewriteReleaseEvidence(): void
    {
        $release = new HistoryRelease('1.0.0', null, null, 'old body', 'old heading');
        $document = new HistoryDocument([$release]);
        $result = $this->importer()->import(
            $document,
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            $this->template(),
            [$this->tag('v1.0.0')],
        );
        self::assertSame($document, $result->document);
        self::assertSame([], $result->missingVersions);
        self::assertSame('1.0.0', $result->currentVersion);
    }

    #[Test]
    public function githubSourceWithoutRepositoryIsAnExplicitError(): void
    {
        $this->expectExceptionMessage('requires an explicit repository');
        $this->importer()->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', source: 'github'),
            $this->template(),
            [],
        );
    }

    #[Test]
    #[TestWith([['name' => 'v1.0.0']])]
    #[TestWith([['name' => 1, 'sha' => 'sha', 'date' => null, 'date_source' => null]])]
    #[TestWith([['name' => 'v1.0.0', 'sha' => 1, 'date' => null, 'date_source' => null]])]
    #[TestWith([['name' => 'v1.0.0', 'sha' => 'sha', 'date' => 1, 'date_source' => null]])]
    #[TestWith([['name' => 'v1.0.0', 'sha' => 'sha', 'date' => null, 'date_source' => 1]])]
    #[TestWith(['invalid'])]
    public function malformedTagEvidenceFailsBeforeAnyImport(mixed $tag): void
    {
        $this->expectExceptionMessage('invalid shape');
        $this->importer()->import(new HistoryDocument(), new ReleaseOptions('/consumer'), $this->template(), [$tag]);
    }

    #[Test]
    public function duplicateStableTagsAreAmbiguousEvenWhenTheyPointToSameSha(): void
    {
        $this->expectExceptionMessage('same canonical stable version');
        $this->importer()->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer'),
            $this->template(),
            [$this->tag('v1.0.0'), $this->tag('v1.0.0')],
        );
    }

    #[Test]
    public function malformedRepositoryFailsBeforeNetwork(): void
    {
        $this->expectExceptionMessage('must use owner/name');
        $this->importer()->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project/evil'),
            $this->template(),
            [$this->tag('v1.0.0')],
        );
    }

    #[Test]
    public function autoDoesNotHidePermissionOrTransportFailure(): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('paginate')->willThrowException(
            new RuntimeException('GitHub API request failed with HTTP 403.'),
        );
        $this->expectExceptionMessage('HTTP 403');
        $this->importer($github)->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            $this->template(),
            [$this->tag('v1.0.0')],
        );
    }

    #[Test]
    #[TestWith([['tag_name' => 'v1.0.0']])]
    #[TestWith([['tag_name' => 'v1.0.0', 'draft' => 'false', 'prerelease' => false]])]
    #[TestWith([['tag_name' => 1, 'draft' => false, 'prerelease' => false]])]
    public function malformedGithubRecordsFailClosed(array $record): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('paginate')->willReturn([$record]);
        $this->expectExceptionMessage('invalid record');
        $this->importer($github)->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            $this->template(),
            [$this->tag('v1.0.0')],
        );
    }

    #[Test]
    public function duplicatePublishedReleaseRecordsAreRejected(): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $release = $this->release('v1.0.0');
        $github->expects(self::once())->method('paginate')->willReturn([$release, $release]);
        $this->expectExceptionMessage('Multiple published');
        $this->importer($github)->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            $this->template(),
            [$this->tag('v1.0.0')],
        );
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith(['invalid'])]
    #[TestWith(['2026-02-29T00:00:00Z'])]
    #[TestWith(['2026-10-03T24:00:00Z'])]
    #[TestWith(['2026-10-03T00:60:00Z'])]
    #[TestWith(['2026-10-03T00:00:60Z'])]
    #[TestWith(['2026-10-03T00:00:00+25:00'])]
    #[TestWith(['2026-10-03T00:00:00+01:60'])]
    public function malformedPublicationDateIsNeverReplacedWithCommitDate(?string $date): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $github->expects(self::once())->method('paginate')->willReturn([$this->release('v1.0.0', 'body', $date)]);
        $this->expectExceptionMessage('valid explicit ISO timestamp');
        $this->importer($github)->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            $this->template(),
            [$this->tag('v1.0.0', '2020-01-01', 'annotated-tag')],
        );
    }

    #[Test]
    public function nonStringGithubBodyProducesDiagnostic(): void
    {
        $github = $this->createMock(GitHubClientInterface::class);
        $record = $this->release('v1.0.0');
        $record['body'] = 42;
        $github->expects(self::once())->method('paginate')->willReturn([$record]);
        $this->expectExceptionMessage('string or null');
        $this->importer($github)->import(
            new HistoryDocument(),
            new ReleaseOptions('/consumer', repository: 'owner/project'),
            $this->template(),
            [$this->tag('v1.0.0')],
        );
    }

    private function importer(?GitHubClientInterface $github = null): HistoryImporter
    {
        if (null === $github) {
            $github = $this->createMock(GitHubClientInterface::class);
            $github->expects(self::never())->method('paginate');
        }
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
            static fn(string $version, ?string $date, ?string $source, string $body): HistoryRelease => new HistoryRelease(
                $version,
                $date,
                $source,
                $body,
            ),
        );
        $results = $this->createStub(HistoryImportResultFactoryInterface::class);
        $results->method('create')->willReturnCallback(
            static fn(HistoryDocument $document, array $missing, string $current): HistoryImportResult => new HistoryImportResult(
                $document,
                $missing,
                $current,
            ),
        );
        $errors = $this->createStub(HistoryExceptionFactoryInterface::class);
        $errors->method('invalid')->willReturnCallback(
            static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message),
        );

        return new HistoryImporter($github, $documents, $releases, $results, $errors);
    }

    private function template(): TemplateInterface
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('missingNotes')->willReturn('No notes available.');

        return $template;
    }

    private function tag(string $name, ?string $date = null, ?string $source = null): array
    {
        return ['name' => $name, 'sha' => str_repeat('a', 40), 'date' => $date, 'date_source' => $source];
    }

    private function release(
        string $tag,
        ?string $body = 'notes',
        ?string $date = '2026-10-03T00:00:00Z',
        bool $draft = false,
        bool $prerelease = false,
    ): array {
        return ['tag_name' => $tag, 'draft' => $draft, 'prerelease' => $prerelease, 'body' => $body, 'published_at' => $date];
    }
}
