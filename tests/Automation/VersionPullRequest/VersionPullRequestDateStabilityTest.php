<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\VersionPullRequest;

use DateTimeImmutable;
use DateTimeZone;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Automation\Policy\PullRequestPolicyInterface;
use FastForward\Changelog\Automation\VersionPullRequest\Factory\VersionPullRequestInputFactory;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestInput;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestResult;
use FastForward\Changelog\Automation\VersionPullRequest\VersionPullRequestService;
use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryDocumentFactory;
use FastForward\Changelog\History\Factory\HistoryReleaseFactory;
use FastForward\Changelog\History\HistoryCodec;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryReferenceBuilder;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\History\Import\HistoryImportResult;
use FastForward\Changelog\Markdown\BlockStructure;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeContinueParser;
use FastForward\Changelog\Markdown\Parser\TrackedFencedCodeStartParser;
use FastForward\Changelog\Markdown\SourceDocument;
use FastForward\Changelog\Release\Factory\ReleasePlanFactory;
use FastForward\Changelog\Release\ReceiptCodec;
use FastForward\Changelog\Release\ReleaseHistoryConsolidator;
use FastForward\Changelog\Release\ReleaseJournalPathResolverInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleasePlanner;
use FastForward\Changelog\Release\ReleaseReceipt;
use FastForward\Changelog\Release\Renderer\ReleaseNotesRendererInterface;
use FastForward\Changelog\Template\Factory\TemplateFactory;
use FastForward\Changelog\Template\KeepAChangelogTemplate;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Tests\Automation\Policy\PolicyFixtureTrait;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use FastForward\Changelog\Validator\ReleaseDateValidator;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface;
use FastForward\Changelog\Version\NextVersionResolverInterface;
use FastForward\Changelog\Version\VersionImpact;
use FastForward\Changelog\Version\VersionResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[UsesClass(BlockStructure::class)]
#[UsesClass(TrackedFencedCodeStartParser::class)]
#[UsesClass(TrackedFencedCodeContinueParser::class)]
#[UsesClass(SourceDocument::class)]
#[CoversClass(VersionPullRequestService::class)]
#[UsesClass(\FastForward\Changelog\Version\VersionBaselineResolver::class)]
#[UsesClass(GitHubEvidence::class)]
#[UsesClass(VersionPullRequestInputFactory::class)]
#[UsesClass(VersionPullRequestInput::class)]
#[UsesClass(VersionPullRequestResult::class)]
#[UsesClass(Category::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(HistoryDocumentFactory::class)]
#[UsesClass(HistoryReleaseFactory::class)]
#[UsesClass(HistoryCodec::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryReferenceBuilder::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(HistoryImportResult::class)]
#[UsesClass(ReleasePlanFactory::class)]
#[UsesClass(ReceiptCodec::class)]
#[UsesClass(ReleaseHistoryConsolidator::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleasePlan::class)]
#[UsesClass(ReleasePlanner::class)]
#[UsesClass(ReleaseReceipt::class)]
#[UsesClass(TemplateFactory::class)]
#[UsesClass(KeepAChangelogTemplate::class)]
#[UsesClass(ValidationReport::class)]
#[UsesClass(ReleaseDateValidator::class)]
#[UsesClass(VersionResolution::class)]
final class VersionPullRequestDateStabilityTest extends TestCase
{
    use PolicyFixtureTrait;

    private array $state = [];
    private array $calls = [];

    /** Exercises real clock-dependent planning, canonical receipts and lossless history with doubled external boundaries. */
    #[Test]
    #[TestWith(['en', []])]
    #[TestWith(['pt-BR', []])]
    #[TestWith(['en', ['release_heading' => '## Release {version}', 'release_heading_dated' => '## Release {version} on {date} ({version})']])]
    public function midnightKeepsTheExactApprovedTransactionAndEveryEmbeddedDate(string $locale, array $overrides): void
    {
        [$service, $options] = $this->fixture($locale, $overrides);
        $first = $service->synchronize($options, new VersionPullRequestInput());
        self::assertSame('created', $first->status, implode('; ', $first->diagnostics));
        $contents = $this->state['contents'][$first->headSha];
        self::assertStringContainsString('2026-10-16', $contents);
        self::assertStringContainsString($this->state['original'], $contents);
        self::assertStringContainsString($this->state['notes'], $contents);
        $this->state['now'] = '2026-10-17T00:00:01Z';
        $this->calls = [];

        $again = $service->synchronize($options, new VersionPullRequestInput());

        self::assertSame('unchanged', $again->status, implode('; ', $again->diagnostics));
        self::assertSame($first->headSha, $again->headSha);
        self::assertSame($first->planId, $again->planId);
        self::assertSame($contents, $this->state['contents'][$again->headSha]);
        self::assertSame([], $this->writes());
        self::assertSame(['2026-10-16T23:59:59Z', '2026-10-17T00:00:01Z'], $this->state['clockReads']);
    }

    /** Recovering a signed orphan after midnight creates only its missing PR, retaining the approved bytes and ID. */
    #[Test]
    public function orphanRecoveryKeepsTheDateAndCommitInsteadOfGeneratingAnotherTransaction(): void
    {
        [$service, $options] = $this->fixture();
        $first = $service->synchronize($options, new VersionPullRequestInput());
        $this->state['pr'] = null;
        $this->state['now'] = '2026-10-17T00:00:01Z';
        $this->calls = [];

        $again = $service->synchronize($options, new VersionPullRequestInput());

        self::assertSame('created', $again->status, implode('; ', $again->diagnostics));
        self::assertSame($first->headSha, $again->headSha);
        self::assertSame($first->planId, $again->planId);
        self::assertSame([['POST', '/repos/owner/project/pulls']], $this->writes());
    }

    /** Ownership and a valid output hash alone cannot excuse any changed planning evidence or noncanonical document. */
    #[Test]
    #[TestWith(['base'])]
    #[TestWith(['fragment_hash'])]
    #[TestWith(['notes'])]
    #[TestWith(['scope'])]
    #[TestWith(['historical_import'])]
    #[TestWith(['locale'])]
    #[TestWith(['template_name'])]
    #[TestWith(['template_bytes'])]
    #[TestWith(['source'])]
    #[TestWith(['plan_trailer'])]
    #[TestWith(['base_trailer'])]
    #[TestWith(['options_trailer'])]
    #[TestWith(['output_trailer'])]
    #[TestWith(['duplicate_trailer'])]
    #[TestWith(['nonstring_message'])]
    #[TestWith(['noncanonical_output'])]
    #[TestWith(['undated_output'])]
    #[TestWith(['missing_version'])]
    public function aRealDifferenceCannotBecomeFalseUnchanged(string $difference): void
    {
        [$service, $options] = $this->fixture();
        $first = $service->synchronize($options, new VersionPullRequestInput());
        $head = $first->headSha;
        $this->state['now'] = '2026-10-17T00:00:01Z';
        switch ($difference) {
            case 'base':
                $this->state['base'] = str_repeat('e', 40);

                break;
            case 'fragment_hash':
                $this->state['fragment'] .= "\n";

                break;
            case 'notes':
                $this->state['notes'] .= "Changed description.\n";

                break;
            case 'scope':
                $this->state['fragmentPath'] = '.changelog/renamed.md';

                break;
            case 'historical_import':
                $this->state['historicalVersions'] = ['0.9.0'];

                break;
            case 'locale':
                $options = new ReleaseOptions('/consumer', locale: 'pt-BR', repository: 'owner/project');

                break;
            case 'template_name':
                $options = new ReleaseOptions('/consumer', template: 'other.php', repository: 'owner/project');

                break;
            case 'template_bytes':
                $this->state['overrides'] = ['release_heading_dated' => '## Release {version} on {date}'];

                break;
            case 'source':
                $options = new ReleaseOptions('/consumer', repository: 'owner/project', source: 'tags');

                break;
            case 'plan_trailer':
                $this->state['messages'][$head] = preg_replace(
                    '/Changelog-Plan: [a-f0-9]+/',
                    'Changelog-Plan: ' . str_repeat('f', 64),
                    $this->state['messages'][$head],
                );

                break;
            case 'base_trailer':
                $this->state['messages'][$head] = str_replace(
                    'Changelog-Base: ' . $this->state['base'],
                    'Changelog-Base: ' . str_repeat('f', 40),
                    $this->state['messages'][$head],
                );

                break;
            case 'options_trailer':
                $this->state['messages'][$head] = preg_replace(
                    '/Changelog-Options: [a-f0-9]+/',
                    'Changelog-Options: ' . str_repeat('f', 64),
                    $this->state['messages'][$head],
                );

                break;
            case 'output_trailer':
                $this->state['messages'][$head] = preg_replace(
                    '/Changelog-Output: [a-f0-9]+/',
                    'Changelog-Output: ' . str_repeat('f', 64),
                    $this->state['messages'][$head],
                );

                break;
            case 'duplicate_trailer':
                $this->state['messages'][$head] .= "\nChangelog-Base: " . $this->state['base'];

                break;
            case 'nonstring_message':
                $this->state['messages'][$head] = null;

                break;
            case 'noncanonical_output':
                $this->state['contents'][$head] .= "\nUnapproved prose.\n";
                $this->refreshOutputHash($head);

                break;
            case 'undated_output':
                $this->state['contents'][$head] = str_replace(
                    '## [1.0.1] - 2026-10-16',
                    '## [1.0.1]',
                    $this->state['contents'][$head],
                );
                $this->refreshOutputHash($head);

                break;
            case 'missing_version':
                $this->state['contents'][$head] = str_replace(
                    '## [1.0.1]',
                    '## [1.0.2]',
                    $this->state['contents'][$head],
                );
                $this->refreshOutputHash($head);

                break;
        }
        $this->calls = [];

        $again = $service->synchronize($options, new VersionPullRequestInput(dryRun: true));

        self::assertSame('dry-run', $again->status, implode('; ', $again->diagnostics));
        self::assertNotSame($first->planId, $again->planId);
        self::assertSame([], $this->writes());
    }

    /** Unknown remote content and invalid calendar dates cannot be used to invent a stable candidate. */
    #[Test]
    #[TestWith(['unavailable'])]
    #[TestWith(['invalid_date'])]
    #[TestWith(['unowned'])]
    #[TestWith(['untrusted_template'])]
    #[TestWith(['template_change_after_plan'])]
    public function missingTrustOrReadableEvidenceFailsClosedWithoutWrites(string $failure): void
    {
        [$service, $options] = $this->fixture();
        $first = $service->synchronize($options, new VersionPullRequestInput());
        $head = $first->headSha;
        $this->state['now'] = '2026-10-17T00:00:01Z';
        if ('invalid_date' === $failure) {
            $this->state['contents'][$head] = str_replace(
                '## [1.0.1] - 2026-10-16',
                '## [1.0.1] - 2026-02-30',
                $this->state['contents'][$head],
            );
            $this->refreshOutputHash($head);
        } else {
            $this->state[$failure] = true;
        }
        $this->calls = [];

        $again = $service->synchronize($options, new VersionPullRequestInput());

        self::assertSame('refused', $again->status);
        self::assertSame([], $this->writes());
        if ('template_change_after_plan' === $failure) {
            self::assertSame(
                2,
                $this->state['templateReads'],
                'A changed template must fail before a second replay execution.',
            );
        }
    }

    /** Replaces synthetic signed evidence only to test that a matching output hash does not prove canonical intent. */
    private function refreshOutputHash(string $head): void
    {
        $this->state['messages'][$head] = preg_replace(
            '/Changelog-Output: [a-f0-9]+/',
            'Changelog-Output: ' . hash('sha256', $this->state['contents'][$head]),
            $this->state['messages'][$head],
        );
    }

    /** Returns only writes, allowing an unchanged transaction to prove absence of all remote mutations. */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, static fn(array $call): bool => 'GET' !== $call[0]));
    }

    /** Composes the real planner and pure codecs; filesystem, GitHub, Git, clock and import observations remain isolated doubles. */
    private function fixture(string $locale = 'en', array $overrides = []): array
    {
        $options = new ReleaseOptions('/consumer', locale: $locale, repository: 'owner/project');
        $this->state = [
            'base' => str_repeat('b', 40), 'head' => null, 'pr' => null, 'sequence' => 0,
            'now' => '2026-10-16T23:59:59Z', 'clockReads' => [], 'messages' => [], 'contents' => [],
            'fragment' => 'accepted fragment bytes', 'fragmentPath' => '.changelog/feature.md',
            'historicalVersions' => [], 'overrides' => $overrides, 'templateReads' => 0,
            'original' => "## [1.0.0] - 2026-10-15\n\nPublished on 2026-10-16.\n\n```md\n## [8.0.0] - 2026-10-17\n```\n\n",
            'notes' => "Description dated 2026-10-16.\n\n```text\n2026-10-17\n```\n",
        ];
        $releases = new HistoryReleaseFactory(new ReleaseDateValidator());
        $history = new HistoryCodec(new HistoryDocumentFactory(), $releases);
        $receipts = new ReceiptCodec();
        $plans = new ReleasePlanFactory($receipts);
        $templates = $this->createStub(TemplateResolverInterface::class);
        $templates->method('resolve')->willReturnCallback(function (ReleaseOptions $received): TemplateInterface {
            ++$this->state['templateReads'];
            if ($this->state['template_change_after_plan'] ?? false) {
                $this->state['untrusted_template'] = true;
            }

            return new TemplateFactory()->create($received->locale, $this->state['overrides']);
        });
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturnCallback(static fn(string $path): string => '/consumer/' . $path);
        $paths->method('relativePath')->willReturnCallback(
            static fn(string $path): string => substr($path, strlen('/consumer/')),
        );
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $files->method('read')->willReturnCallback(
            fn(string $path): ?string => '/consumer/CHANGELOG.md' === $path ? $this->state['original'] : null,
        );
        $files->expects(self::never())->method('write');
        $files->expects(self::never())->method('remove');
        $git = $this->createStub(GitRepositoryInterface::class);
        $git->method('repositoryRoot')->willReturn('/consumer');
        $git->method('isRepository')->willReturn(true);
        $git->method('resolveRef')->willReturnCallback(fn(): string => $this->state['base']);
        $git->method('tags')->willReturnCallback(fn(): array => [[
            'name' => 'v1.0.0', 'sha' => $this->state['base'], 'date' => null, 'date_source' => null,
        ]]);
        $git->method('isAncestor')->willReturn(true);
        $git->method('readFileAt')->willReturnCallback(
            fn(string $directory, string $sha, string $path): ?string => 'CHANGELOG.md' === $path
                ? $this->state['original'] : $this->state['fragment'],
        );
        $importer = $this->createStub(HistoryImporterInterface::class);
        $importer->method('currentVersion')->willReturn('1.0.0');
        $importer->method('import')->willReturnCallback(
            fn(HistoryDocument $document): HistoryImportResult => new HistoryImportResult(
                $document,
                $this->state['historicalVersions'],
                '1.0.0',
            ),
        );
        $validator = $this->createStub(ChangesetValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(fn(): ValidationReport => new ValidationReport(
            [new Changeset('feature.md', Category::Fixed, null, null, null, 'Description')],
            [],
            false,
            ['/consumer/' . $this->state['fragmentPath'] => hash('sha256', $this->state['fragment'])],
        ));
        $versions = $this->createStub(NextVersionResolverInterface::class);
        $versions->method('resolve')->willReturn(new VersionResolution('1.0.1', VersionImpact::Patch, []));
        $renderer = $this->createStub(ReleaseNotesRendererInterface::class);
        $renderer->method('render')->willReturnCallback(fn(): string => $this->state['notes']);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(function (): DateTimeImmutable {
            $this->state['clockReads'][] = $this->state['now'];

            return new DateTimeImmutable($this->state['now']);
        });
        $inputEvidence = $this->createStub(ReleaseInputEvidenceValidatorInterface::class);
        $inputEvidence->method('validateTemplate')->willReturnCallback(function (): void {
            if ($this->state['untrusted_template'] ?? false) {
                throw new RuntimeException('Untrusted template.');
            }
        });
        $journals = $this->createStub(ReleaseJournalPathResolverInterface::class);
        $journals->method('resolve')->willReturn('/isolated/release-journal');
        $planner = new ReleasePlanner(
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
            $inputEvidence,
            $journals,
            $this->createStub(\FastForward\Changelog\Release\Reference\PullRequestReferenceResolverInterface::class),
        );
        $github = $this->createStub(GitHubClientInterface::class);
        $github->method('paginate')->willReturnCallback(
            fn(): array => null === $this->state['pr'] ? [] : [$this->state['pr']],
        );
        $github->method('request')->willReturnCallback(
            function (string $method, string $path, ?array $body = null): ?array {
                $this->calls[] = [$method, $path];
                if ('GET' === $method) {
                    if (str_ends_with($path, '/git/ref/heads/main')) {
                        return ['object' => ['type' => 'commit', 'sha' => $this->state['base']]];
                    }
                    if (str_ends_with($path, '/git/ref/heads/changelog%2Fversion')) {
                        return null === $this->state['head'] ? null : ['object' => ['type' => 'commit', 'sha' => $this->state['head']]];
                    }
                    if (str_starts_with($path, '/users/')) {
                        return $this->account('github-actions[bot]', 'Bot', 101);
                    }
                    if (str_contains($path, '/git/commits/')) {
                        return ['tree' => ['sha' => str_repeat('d', 40)]];
                    }
                    if (str_contains($path, '/commits/')) {
                        return ['commit' => ['message' => $this->state['messages'][basename($path)]]];
                    }
                    if (str_contains($path, '/contents/')) {
                        return ($this->state['unavailable'] ?? false) ? null : $this->file(
                            $this->state['contents'][substr($path, -40)],
                        );
                    }
                    if (str_contains($path, '/pulls/')) {
                        return $this->state['pr'];
                    }
                }
                if ('POST' === $method && str_ends_with($path, '/git/trees')) {
                    $this->state['pendingContents'] = $body['tree'][0]['content'];

                    return ['sha' => str_repeat('e', 40)];
                }
                if ('POST' === $method && str_ends_with($path, '/git/commits')) {
                    $sha = sha1('synthetic-commit-' . ++$this->state['sequence']);
                    $this->state['messages'][$sha] = $body['message'];
                    $this->state['contents'][$sha] = $this->state['pendingContents'];

                    return ['sha' => $sha];
                }
                if (str_contains($path, '/git/refs')) {
                    $this->state['head'] = $body['sha'];

                    return ['object' => ['type' => 'commit', 'sha' => $this->state['head']]];
                }
                if (str_contains($path, '/pulls')) {
                    $pr = $this->pr('github-actions[bot]', 'Bot', 'changelog/version');
                    $pr['head']['sha'] = $this->state['head'];
                    $pr['base']['sha'] = $this->state['base'];
                    $pr['html_url'] = 'https://github.com/owner/project/pull/7';
                    $pr['title'] = $body['title'];
                    $pr['body'] = $body['body'] ?? ($this->state['pr']['body'] ?? null);
                    $this->state['pr'] = $pr;

                    return $pr;
                }

                throw new RuntimeException('Unexpected synthetic endpoint: ' . $path);
            },
        );
        $policy = $this->createStub(PullRequestPolicyInterface::class);
        $policy->method('inspectHead')->willReturnCallback(fn(): bool => ! ($this->state['unowned'] ?? false));

        return [new VersionPullRequestService(
            $github,
            $git,
            $planner,
            $receipts,
            $paths,
            $policy,
            new VersionPullRequestInputFactory(),
            $inputEvidence,
            $history,
            $templates,
            $releases,
            $plans,
        ), $options];
    }
}
