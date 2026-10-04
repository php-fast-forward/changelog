<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validator;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Publication\Factory\PublicationEvidenceFactoryInterface;
use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseNotesRendererInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleaseReceipt;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Validator\PublicationEvidenceValidator;
use FastForward\Changelog\Version\NextVersionResolverInterface;
use FastForward\Changelog\Version\VersionResolution;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(PublicationEvidenceValidator::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(ChangesetParseResult::class)]
#[UsesClass(Category::class)]
#[UsesClass(VersionImpact::class)]
#[UsesClass(VersionResolution::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
#[UsesClass(PublicationEvidence::class)]
#[UsesClass(ReleaseOptions::class)]
#[UsesClass(ReleaseReceipt::class)]
final class PublicationEvidenceValidatorTest extends TestCase
{
    /** The proof MUST use approved/base blobs, exact full consumption, reachable tags and recomputed notes. */
    public function testValidCommittedEvidenceProducesExactCentralNotesWithoutReadingCurrentFiles(): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        $evidence = $this->validator($state)->validate($options, str_repeat('b', 40));
        self::assertSame(str_repeat('b', 40), $evidence->sha);
        self::assertSame('1.0.1', $evidence->version);
        self::assertSame('v1.0.1', $evidence->tag);
        self::assertSame("Exact  notes\n", $evidence->notes);
        self::assertSame([$state['tags'][0]], $state['reachable']);
        self::assertSame(['a.md', 'b.md'], $state['resolved_ids']);
        self::assertSame(0, $state['live_reads']);
        self::assertNotContains('HEAD', $state['resolved_refs']);
        self::assertCount(1, $state['created']);
    }

    /** Nullable original history and a SHA-256 approved commit MUST remain supported. */
    public function testOriginallyAbsentCentralAndCompleteSha256ApprovedCommitAreSupported(): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        $state['data']['before_changelog_sha256'] = null;
        $state['blobs'][str_repeat('a', 40)]['CHANGELOG.md'] = null;
        $sha = str_repeat('b', 64);
        $state['blobs'][$sha] = $state['blobs'][str_repeat('b', 40)];
        $state['tree'][$sha] = $state['tree'][str_repeat('b', 40)];
        $state['resolved'] = $sha;
        $evidence = $this->validator($state)->validate($options, $sha);
        self::assertSame($sha, $evidence->sha);
    }

    /** Maintenance MUST verify snapshot bytes but never resolve a version, execute a template or publish notes. */
    public function testMaintenanceProducesNoTagOrNotes(): void
    {
        $options = new ReleaseOptions('/consumer');
        $state = $this->state($options);
        $state['data']['next_version'] = null;
        $state['data']['impact'] = null;
        $state['data']['consumed'] = [];
        $evidence = $this->validator($state)->validate($options, str_repeat('b', 40));
        self::assertNull($evidence->version);
        self::assertNull($evidence->tag);
        self::assertSame('', $evidence->notes);
        self::assertSame(0, $state['template_resolves']);
        self::assertSame([], $state['resolved_ids']);
        self::assertSame([], $state['ancestry_calls']);
    }

    /** A branch, abbreviated hash, moved explicit identity or unsafe output blob MUST fail before evidence creation. */
    #[DataProvider('earlyFailures')]
    public function testEarlyEvidenceFailuresProduceNoApprovedOutput(array $changes, string $sha, string $message): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = array_replace($this->state($options), $changes);
        $this->failure($options, $state, $message, $sha);
        self::assertSame([], $state['created']);
    }

    /** Supplies invalid approved identity and a different resolved target. */
    public static function earlyFailures(): iterable
    {
        yield [[], 'HEAD', 'complete exact approved'];
        yield [[], 'abc', 'complete exact approved'];
        yield [['resolved' => str_repeat('c', 40)], str_repeat('b', 40), 'complete exact approved'];
    }

    /** Every committed evidence mismatch MUST reject publication before any approved result is built. */
    #[DataProvider('evidenceFailures')]
    public function testDivergentCommittedEvidenceIsRejected(string $mutation, string $message): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        $base = str_repeat('a', 40);
        $approved = str_repeat('b', 40);
        switch ($mutation) {
            case 'receipt-mode': $state['tree'][$approved]['.changelog/release-plan.json'] = '120000';
                break;
            case 'receipt-missing': $state['blobs'][$approved]['.changelog/release-plan.json'] = null;
                break;
            case 'settings': $state['data']['locale'] = 'pt-BR';
                break;
            case 'central-mode': $state['tree'][$approved]['CHANGELOG.md'] = '160000';
                break;
            case 'central-bytes': $state['blobs'][$approved]['CHANGELOG.md'] = 'changed';
                break;
            case 'central-missing': $state['blobs'][$approved]['CHANGELOG.md'] = null;
                break;
            case 'base-null': $state['data']['base_sha'] = null;
                break;
            case 'base-ancestry': $state['base_ancestor'] = false;
                break;
            case 'base-central': $state['blobs'][$base]['CHANGELOG.md'] = 'different before';
                break;
            case 'base-central-mode': $state['tree'][$base]['CHANGELOG.md'] = '120000';
                break;
            case 'extra-base-fragment': $state['tree'][$base]['.changelog/extra.md'] = '100644';
                break;
            case 'approved-new-fragment': $state['tree'][$approved]['.changelog/new.md'] = '100644';
                break;
            case 'approved-nested-fragment': $state['tree'][$approved]['.changelog/nested/new.md'] = '100644';
                break;
            case 'approved-hidden-fragment': $state['tree'][$approved]['.changelog/.hidden.md'] = '120000';
                break;
            case 'missing-base-fragment': unset($state['tree'][$base]['.changelog/a.md']);
                break;
            case 'hidden-base-fragment': $state['tree'][$base]['.changelog/.hidden.md'] = '100644';
                break;
            case 'nested-base-fragment': $state['tree'][$base]['.changelog/nested/a.md'] = '100644';
                break;
            case 'symbolic-fragment': $state['tree'][$base]['.changelog/a.md'] = '120000';
                break;
            case 'fragment-hash': $state['blobs'][$base]['.changelog/a.md'] = 'modified';
                break;
            case 'fragment-missing': $state['blobs'][$base]['.changelog/a.md'] = null;
                break;
            case 'fragment-invalid': $state['parser_valid'] = false;
                break;
            case 'fragment-pending': $state['blobs'][$approved]['.changelog/a.md'] = 'alpha';
                break;
            case 'current-version': $state['current'] = '9.0.0';
                break;
            case 'next-version': $state['next'] = '1.0.2';
                break;
            case 'impact': $state['impact'] = VersionImpact::Major;
                break;
            case 'resolution': $state['resolution_errors'] = ['invalid'];
                break;
            case 'central-notes': $state['central_notes'] = 'edited central notes';
                break;
            case 'notes-hash': $state['data']['notes_sha256'] = str_repeat('f', 64);
                break;
            case 'generated-notes': $state['expected_notes'] = 'not matching complete fragments';
                break;
        }
        $this->failure($options, $state, $message);
        self::assertSame([], $state['created']);
    }

    /** Supplies malformed modes, tampered blobs, incomplete consumption and changed domain semantics. */
    public static function evidenceFailures(): iterable
    {
        yield ['receipt-mode', 'exact regular committed'];
        yield ['receipt-missing', 'has no release receipt'];
        yield ['settings', 'Publication setting differs'];
        yield ['central-mode', 'exact regular committed'];
        yield ['central-bytes', 'differs from its receipt snapshot'];
        yield ['central-missing', 'differs from its receipt snapshot'];
        yield ['base-null', 'receipt base ancestry'];
        yield ['base-ancestry', 'receipt base ancestry'];
        yield ['base-central', 'original changelog hash'];
        yield ['base-central-mode', 'exact regular committed'];
        yield ['approved-new-fragment', 'still contains pending Markdown'];
        yield ['approved-nested-fragment', 'still contains pending Markdown'];
        yield ['approved-hidden-fragment', 'still contains pending Markdown'];
        yield ['extra-base-fragment', 'complete pending fragment set'];
        yield ['missing-base-fragment', 'complete pending fragment set'];
        yield ['hidden-base-fragment', 'unsafe or noncanonical'];
        yield ['nested-base-fragment', 'unsafe or noncanonical'];
        yield ['symbolic-fragment', 'unsafe or noncanonical'];
        yield ['fragment-hash', 'committed base hash'];
        yield ['fragment-missing', 'committed base hash'];
        yield ['fragment-invalid', 'base fragment is invalid'];
        yield ['fragment-pending', 'still exists at the approved'];
        yield ['current-version', 'version or impact'];
        yield ['next-version', 'version or impact'];
        yield ['impact', 'version or impact'];
        yield ['resolution', 'version or impact'];
        yield ['central-notes', 'central section notes differ'];
        yield ['notes-hash', 'central section notes differ'];
        yield ['generated-notes', 'canonical notes generated'];
    }

    /** A GitHub repository MUST be explicit and agree with the approved receipt before release proof. */
    public function testReleaseWithoutRepositoryCannotBeApproved(): void
    {
        $options = new ReleaseOptions('/consumer');
        $state = $this->state($options);
        $this->failure($options, $state, 'selected GitHub repository');
    }

    /** Custom executable templates MUST match a regular committed blob and exact approved HEAD before resolution. */
    public function testTrustedCustomTemplateIsResolvedOnlyAfterCheckoutAndByteProof(): void
    {
        $options = new ReleaseOptions('/consumer', template: 'custom.php', repository: 'owner/repo');
        $state = $this->state($options);
        $evidence = $this->validator($state)->validate($options, str_repeat('b', 40));
        self::assertSame('1.0.1', $evidence->version);
        self::assertSame(1, $state['live_reads']);
        self::assertContains('HEAD', $state['resolved_refs']);
        self::assertSame(1, $state['template_resolves']);
    }

    /** Dirty, missing, symbolic, outside-root or moved-checkout custom templates MUST never be executed. */
    #[DataProvider('unsafeCustomTemplates')]
    public function testUntrustedCustomTemplateFailsBeforeResolution(string $path, string $mutation, string $message): void
    {
        $options = new ReleaseOptions('/consumer', template: $path, repository: 'owner/repo');
        $state = $this->state($options);
        $sha = str_repeat('b', 40);
        if ('head' === $mutation) {
            $state['head'] = str_repeat('c', 40);
        }
        if ('live' === $mutation) {
            $state['live_template'] = 'dirty edited PHP';
        }
        if ('missing' === $mutation) {
            $state['blobs'][$sha][$path] = null;
        }
        if ('mode' === $mutation) {
            $state['tree'][$sha][$path] = '120000';
        }
        $this->failure($options, $state, $message);
        self::assertSame(0, $state['template_resolves']);
    }

    /** Supplies every unsafe custom selection and source mismatch. */
    public static function unsafeCustomTemplates(): iterable
    {
        yield ['/absolute.php', '', 'canonical tracked path'];
        yield ['../outside.php', '', 'canonical tracked path'];
        yield ['a\\b.php', '', 'canonical tracked path'];
        yield ["nul\0path", '', 'canonical tracked path'];
        yield ['', '', 'canonical tracked path'];
        yield ['custom.php', 'head', 'approved checkout'];
        yield ['custom.php', 'live', 'exact approved committed blob'];
        yield ['custom.php', 'missing', 'exact approved committed blob'];
        yield ['custom.php', 'mode', 'exact regular committed file'];
    }

    /** Captures publication proof diagnostics without involving a remote client. */
    private function failure(ReleaseOptions $options, array &$state, string $message, ?string $sha = null): void
    {
        try {
            $this->validator($state)->validate($options, $sha ?? str_repeat('b', 40));
            self::fail('Invalid committed evidence must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    /** Supplies exact in-memory base and approved commit trees plus deterministic domain outputs. */
    private function state(ReleaseOptions $options): array
    {
        $base = str_repeat('a', 40);
        $approved = str_repeat('b', 40);
        $data = ['base_sha' => $base, 'current_version' => '1.0.0', 'next_version' => '1.0.1', 'impact' => 'patch',
            'consumed' => ['.changelog/a.md' => hash('sha256', 'alpha'), '.changelog/b.md' => hash('sha256', 'beta')],
            'fragment_directory' => '.changelog', 'changelog_file' => 'CHANGELOG.md', 'locale' => $options->locale, 'template' => $options->template,
            'tag_prefix' => $options->tagPrefix, 'repository' => $options->repository, 'changelog_contents' => 'after',
            'before_changelog_sha256' => hash('sha256', 'before'), 'after_changelog_sha256' => hash('sha256', 'after'),
            'notes' => "Exact  notes\n", 'notes_sha256' => hash('sha256', "Exact  notes\n")];
        return ['data' => $data, 'resolved' => $approved, 'head' => $approved, 'resolved_refs' => [], 'created' => [], 'ancestry_calls' => [],
            'base_ancestor' => true, 'parser_valid' => true, 'current' => '1.0.0', 'next' => '1.0.1', 'impact' => VersionImpact::Patch,
            'resolution_errors' => [], 'central_notes' => "Exact  notes\n", 'rendered_notes' => "Exact  notes\n", 'expected_notes' => "Exact  notes\n",
            'live_template' => '<?php return [];', 'live_reads' => 0, 'template_resolves' => 0, 'resolved_ids' => [], 'reachable' => [],
            'tags' => [['name' => 'v1.0.0', 'sha' => str_repeat('c', 40), 'date' => null, 'date_source' => null], ['name' => 'v9.0.0', 'sha' => str_repeat('d', 40), 'date' => null, 'date_source' => null]],
            'blobs' => [$base => ['CHANGELOG.md' => 'before', '.changelog/a.md' => 'alpha', '.changelog/b.md' => 'beta'],
                $approved => ['CHANGELOG.md' => 'after', '.changelog/release-plan.json' => 'raw receipt', $options->template => '<?php return [];']],
            'tree' => [$base => ['CHANGELOG.md' => '100644', '.changelog/a.md' => '100644', '.changelog/b.md' => '100755', '.changelog/AGENTS.md' => '100644', '.changelog/release-plan.json' => '100644'],
                $approved => ['CHANGELOG.md' => '100644', '.changelog/release-plan.json' => '100644', '.changelog/AGENTS.md' => '100644', $options->template => '100644']]];
    }

    /** Replaces every Git, file, parser, resolver, renderer and factory collaborator with a deterministic double. */
    private function validator(array &$state): PublicationEvidenceValidator
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::never())->method('commitFragment');
        $git->method('resolveRef')->willReturnCallback(static function (string $directory, string $reference) use (&$state): string {
            self::assertSame('/consumer', $directory);
            $state['resolved_refs'][] = $reference;
            return 'HEAD' === $reference ? $state['head'] : $state['resolved'];
        });
        $git->method('filesAt')->willReturnCallback(static function (string $directory, string $sha, string $path) use (&$state): array {
            self::assertSame('/consumer', $directory);
            $entries = [];
            foreach ($state['tree'][$sha] ?? [] as $name => $mode) {
                if ($name === $path || str_starts_with($name, $path . '/')) {
                    $entries[] = ['path' => $name, 'mode' => $mode];
                }
            }
            return $entries;
        });
        $git->method('readFileAt')->willReturnCallback(static function (string $directory, string $sha, string $path) use (&$state): ?string {
            self::assertSame('/consumer', $directory);
            return $state['blobs'][$sha][$path] ?? null;
        });
        $git->method('isAncestor')->willReturnCallback(static function (string $directory, string $ancestor, string $descendant) use (&$state): bool {
            $state['ancestry_calls'][] = [$ancestor, $descendant];
            if ($ancestor === str_repeat('a', 40)) {
                return $state['base_ancestor'];
            }
            return $ancestor === str_repeat('c', 40);
        });
        $git->method('tags')->willReturnCallback(static fn(string $directory): array => $state['tags']);
        $receipts = $this->createStub(ReceiptCodecInterface::class);
        $receipts->method('decode')->willReturnCallback(static function (string $raw) use (&$state): ReleaseReceipt {
            self::assertSame('raw receipt', $raw);
            return new ReleaseReceipt($state['data']);
        });
        $parser = $this->createStub(ChangesetParserInterface::class);
        $parser->method('parse')->willReturnCallback(static function (string $path, string $contents) use (&$state): ChangesetParseResult {
            $id = basename($path);
            return $state['parser_valid'] ? new ChangesetParseResult($id, new Changeset($id, Category::Fixed, null, null, null, $contents, VersionImpact::Patch), []) : new ChangesetParseResult($id, null, ['invalid metadata']);
        });
        $versions = $this->createStub(NextVersionResolverInterface::class);
        $versions->method('resolve')->willReturnCallback(static function (string $current, array $changes) use (&$state): VersionResolution {
            self::assertSame($state['current'], $current);
            $state['resolved_ids'] = array_map(static fn(Changeset $change): string => $change->id, $changes);
            return new VersionResolution($state['next'], $state['impact'], $state['resolution_errors']);
        });
        $importer = $this->createStub(HistoryImporterInterface::class);
        $importer->method('currentVersion')->willReturnCallback(static function (array $tags, string $prefix) use (&$state): string {
            self::assertSame('v', $prefix);
            $state['reachable'] = $tags;
            return $state['current'];
        });
        $centralDoc = new HistoryDocument([new HistoryRelease('1.0.1', body: $state['central_notes'])]);
        $expectedDoc = new HistoryDocument([new HistoryRelease('1.0.1', body: $state['expected_notes'])]);
        $history = $this->createStub(HistoryCodecInterface::class);
        $history->method('parse')->willReturnCallback(static function (string $contents) use ($centralDoc, $expectedDoc): HistoryDocument {
            self::assertContains($contents, ['after', 'isolated rendered']);
            return 'after' === $contents ? $centralDoc : $expectedDoc;
        });
        $history->method('notes')->willReturnCallback(static fn(HistoryDocument $doc, string $version): string => $doc->getRelease($version)->getBody());
        $history->method('render')->willReturnCallback(static function (HistoryDocument $doc, TemplateInterface $template) use (&$state): string {
            self::assertSame($state['rendered_notes'], $doc->getReleases()[0]->getBody());
            return 'isolated rendered';
        });
        $template = $this->createStub(TemplateInterface::class);
        $templates = $this->createStub(TemplateResolverInterface::class);
        $templates->method('resolve')->willReturnCallback(static function (ReleaseOptions $options) use ($template, &$state): TemplateInterface {
            ++$state['template_resolves'];
            return $template;
        });
        $notes = $this->createStub(ReleaseNotesRendererInterface::class);
        $notes->method('render')->willReturnCallback(static function (array $changes, TemplateInterface $actual, ?string $repository) use ($template, &$state): string {
            self::assertSame($template, $actual);
            self::assertSame('owner/repo', $repository);
            return $state['rendered_notes'];
        });
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturnCallback(static fn(string $path, ?string $directory = null): string => '/consumer/' . $path);
        $paths->method('isAbsolute')->willReturnCallback(static fn(string $path): bool => str_starts_with($path, '/'));
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('read')->willReturnCallback(static function (string $path) use (&$state): ?string {
            ++$state['live_reads'];
            return $state['live_template'];
        });
        $releases = $this->createStub(HistoryReleaseFactoryInterface::class);
        $releases->method('create')->willReturnCallback(static fn(string $version, ?string $date, ?string $source, string $body): HistoryRelease => new HistoryRelease($version, $date, $source, $body));
        $documents = $this->createStub(HistoryDocumentFactoryInterface::class);
        $documents->method('create')->willReturnCallback(static fn(array $releases): HistoryDocument => new HistoryDocument($releases));
        $evidence = $this->createStub(PublicationEvidenceFactoryInterface::class);
        $evidence->method('create')->willReturnCallback(static function (string $sha, ?string $version, ?string $tag, string $notes, ?string $repository) use (&$state): PublicationEvidence {
            $state['created'][] = [$sha, $version, $tag, $notes, $repository];
            return new PublicationEvidence($sha, $version, $tag, $notes, $repository);
        });
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message, ?Throwable $previous = null): InvalidArgumentException => new InvalidArgumentException($message, previous: $previous));
        return new PublicationEvidenceValidator($git, $receipts, $history, $parser, $versions, $importer, $notes, $templates, $paths, $files, $releases, $documents, $evidence, $exceptions);
    }
}
