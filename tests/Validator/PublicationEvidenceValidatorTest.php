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
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\History\Import\HistoryImportResult;
use FastForward\Changelog\Publication\Factory\PublicationEvidenceFactoryInterface;
use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseNotesRendererInterface;
use FastForward\Changelog\Release\ReleaseOptions;
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
#[UsesClass(HistoryImportResult::class)]
#[UsesClass(PublicationEvidence::class)]
#[UsesClass(ReleaseOptions::class)]
final class PublicationEvidenceValidatorTest extends TestCase
{
    private const string BASE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string APPROVED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** Exact approved/base blobs, complete consumption, reachable tags and complete prior history define publication. */
    public function testCommittedEvidenceReconstructsWholeHistoryWithoutReadingWorkingFilesOrReceipt(): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        $evidence = $this->validator($state)->validate($options, self::APPROVED);
        self::assertSame(self::APPROVED, $evidence->sha);
        self::assertSame('1.0.1', $evidence->version);
        self::assertSame('v1.0.1', $evidence->tag);
        self::assertSame("Exact  notes\n", $evidence->notes);
        self::assertSame([$state['tags'][0]], $state['reachable']);
        self::assertSame(['a.md', 'b.md'], $state['resolved_ids']);
        self::assertSame(['1.0.1', '1.0.0'], $state['rendered_versions']);
        self::assertSame('Original introduction', $state['rendered_prefix']);
        self::assertSame('[prior]: https://example.test/prior', $state['rendered_references']);
        self::assertSame(0, $state['live_reads']);
        self::assertNotContains('HEAD', $state['resolved_refs']);
        self::assertCount(1, $state['created']);
        self::assertNotContains('.changelog/release-plan.json', $state['read_paths']);
    }

    /** Originally absent history and complete SHA-256 identities remain valid with exact committed evidence. */
    public function testAbsentOriginalHistoryAndSha256CommitAreSupported(): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        $state['base_doc'] = new HistoryDocument([], 'Original introduction', '[prior]: https://example.test/prior');
        $state['blobs'][self::BASE]['CHANGELOG.md'] = null;
        $state['approved_doc'] = $state['base_doc']->withReleases([new HistoryRelease('1.0.1', '2026-10-03', null, "Exact  notes\n")]);
        $this->refreshApproved($state);
        $sha = str_repeat('b', 64);
        $state['blobs'][$sha] = $state['blobs'][self::APPROVED];
        $state['tree'][$sha] = $state['tree'][self::APPROVED];
        $state['resolved'] = $sha;
        self::assertSame($sha, $this->validator($state)->validate($options, $sha)->sha);
    }

    /** A pending Unreleased section stays first while approved release notes and older history remain exact. */
    public function testNewSectionFollowsExistingUnreleasedSection(): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        $pending = new HistoryRelease('unreleased', body: 'Existing pending prose');
        $state['base_doc'] = $state['base_doc']->withReleases([$pending, ...$state['base_doc']->getReleases()]);
        $state['approved_doc'] = $state['approved_doc']->withReleases([$pending, ...$state['approved_doc']->getReleases()]);
        $state['blobs'][self::BASE]['CHANGELOG.md'] = $this->encode($state['base_doc']);
        $this->refreshApproved($state);
        self::assertSame('1.0.1', $this->validator($state)->validate($options, self::APPROVED)->version);
        self::assertSame(['unreleased', '1.0.1', '1.0.0'], $state['rendered_versions']);
    }

    /** Generated maintenance metadata with no source fragments cannot produce a tag or execute templates. */
    public function testVerifiedMaintenanceProducesNoPublication(): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        unset($state['tree'][self::BASE]['.changelog/a.md'], $state['tree'][self::BASE]['.changelog/b.md']);
        $evidence = $this->validator($state)->validate($options, self::APPROVED);
        self::assertNull($evidence->version);
        self::assertNull($evidence->tag);
        self::assertSame('', $evidence->notes);
        self::assertSame(0, $state['template_resolves']);
        self::assertSame([], $state['resolved_ids']);
        self::assertSame([[self::BASE, self::APPROVED]], $state['ancestry_calls']);
    }

    /** History maintenance preserves the complete pending set without parsing fragments or calculating a new release. */
    public function testMaintenancePreservesRegularPendingFragmentPathsAndExactBytes(): void
    {
        $options = new ReleaseOptions('/consumer', template: 'custom.php', repository: 'owner/repo');
        $state = $this->pendingMaintenanceState($options);
        $state['parser_valid'] = false;
        $state['resolution_errors'] = ['must not calculate a version'];
        $evidence = $this->validator($state)->validate($options, self::APPROVED);
        self::assertNull($evidence->version);
        self::assertNull($evidence->tag);
        self::assertSame('', $evidence->notes);
        self::assertSame(0, $state['template_resolves']);
        self::assertSame(0, $state['live_reads']);
        self::assertSame(0, $state['parser_calls']);
        self::assertSame(0, $state['history_parse_calls']);
        self::assertSame(0, $state['tags_calls']);
        self::assertSame([], $state['resolved_ids']);
        self::assertSame([], $state['rendered_versions']);
    }

    /** Maintenance cannot disguise partial consumption, extra paths, unsafe modes or mutated pending bytes. */
    #[DataProvider('pendingMaintenanceFailures')]
    public function testMaintenanceRejectsChangedPendingFragmentSet(string $mutation, string $diagnostic): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->pendingMaintenanceState($options);
        switch ($mutation) {
            case 'source-zero': unset($state['tree'][self::BASE]['.changelog/a.md'], $state['tree'][self::BASE]['.changelog/b.md']);
                break;
            case 'partial': unset($state['tree'][self::APPROVED]['.changelog/b.md'], $state['blobs'][self::APPROVED]['.changelog/b.md']);
                break;
            case 'added': $state['tree'][self::APPROVED]['.changelog/extra.md'] = '100644';
                $state['blobs'][self::APPROVED]['.changelog/extra.md'] = 'extra';
                break;
            case 'changed': $state['blobs'][self::APPROVED]['.changelog/a.md'] = 'edited';
                break;
            case 'missing-target': $state['blobs'][self::APPROVED]['.changelog/a.md'] = null;
                break;
            case 'missing-base': $state['blobs'][self::BASE]['.changelog/a.md'] = null;
                break;
            case 'symbolic': $state['tree'][self::APPROVED]['.changelog/a.md'] = '120000';
                break;
            case 'nested': $state['tree'][self::APPROVED]['.changelog/nested/a.md'] = '100644';
                break;
            case 'hidden': $state['tree'][self::APPROVED]['.changelog/.hidden.md'] = '100644';
                break;
        }
        $this->failure($options, $state, $diagnostic);
        self::assertSame(0, $state['template_resolves']);
        self::assertSame(0, $state['parser_calls']);
    }

    /** Supplies preservation failures separately from complete consumption release checks. */
    public static function pendingMaintenanceFailures(): iterable
    {
        yield ['source-zero', 'pending'];
        yield ['partial', 'pending'];
        yield ['added', 'pending'];
        yield ['changed', 'pending'];
        yield ['missing-target', 'pending'];
        yield ['missing-base', 'pending'];
        yield ['symbolic', 'unsafe'];
        yield ['nested', 'unsafe'];
        yield ['hidden', 'unsafe'];
    }

    /** Publication never accepts a branch, abbreviated SHA or a moved explicit identity. */
    #[DataProvider('invalidApprovedIdentities')]
    public function testInvalidApprovedIdentityFailsBeforeEvidenceCreation(string $sha, ?string $resolved): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        $state['resolved'] = $resolved ?? self::APPROVED;
        $this->failure($options, $state, 'complete exact approved', $sha);
    }

    /** Supplies revision aliases, abbreviated and unexpectedly resolved commit identities. */
    public static function invalidApprovedIdentities(): iterable
    {
        yield ['HEAD', null];
        yield ['abc', null];
        yield [self::APPROVED, str_repeat('c', 40)];
    }

    /** Every changed committed input or self-consistent forged output fails before any approved evidence exists. */
    #[DataProvider('evidenceFailures')]
    public function testDivergentCommittedEvidenceIsRejected(string $mutation, string $message): void
    {
        $options = new ReleaseOptions('/consumer', repository: 'owner/repo');
        $state = $this->state($options);
        switch ($mutation) {
            case 'metadata-missing': $state['metadata'] = null;
                break;
            case 'settings': $state['metadata']['options_sha256'] = str_repeat('f', 64);
                break;
            case 'central-mode': $state['tree'][self::APPROVED]['CHANGELOG.md'] = '160000';
                break;
            case 'central-missing': $state['blobs'][self::APPROVED]['CHANGELOG.md'] = null;
                break;
            case 'central-bytes': $state['blobs'][self::APPROVED]['CHANGELOG.md'] = 'edited';
                break;
            case 'base-ancestry': $state['base_ancestor'] = false;
                break;
            case 'base-central-mode': $state['tree'][self::BASE]['CHANGELOG.md'] = '120000';
                break;
            case 'extra-base-fragment': $state['tree'][self::BASE]['.changelog/extra.md'] = '100644';
                $state['blobs'][self::BASE]['.changelog/extra.md'] = 'extra';
                break;
            case 'approved-new-fragment': $state['tree'][self::APPROVED]['.changelog/new.md'] = '100644';
                break;
            case 'approved-nested-fragment': $state['tree'][self::APPROVED]['.changelog/nested/new.md'] = '100644';
                break;
            case 'approved-hidden-fragment': $state['tree'][self::APPROVED]['.changelog/.hidden.md'] = '120000';
                break;
            case 'missing-base-fragment': unset($state['tree'][self::BASE]['.changelog/a.md']);
                break;
            case 'hidden-base-fragment': $state['tree'][self::BASE]['.changelog/.hidden.md'] = '100644';
                break;
            case 'nested-base-fragment': $state['tree'][self::BASE]['.changelog/nested/a.md'] = '100644';
                break;
            case 'outside-base-fragment': $state['outside_fragment'] = true;
                break;
            case 'symbolic-fragment': $state['tree'][self::BASE]['.changelog/a.md'] = '120000';
                break;
            case 'fragment-bytes': $state['blobs'][self::BASE]['.changelog/a.md'] = 'modified';
                break;
            case 'fragment-missing': $state['blobs'][self::BASE]['.changelog/a.md'] = null;
                break;
            case 'fragment-invalid': $state['parser_valid'] = false;
                break;
            case 'fragment-pending': $state['blobs'][self::APPROVED]['.changelog/a.md'] = 'alpha';
                break;
            case 'next-version': $state['next'] = '1.0.2';
                break;
            case 'resolution': $state['resolution_errors'] = ['invalid'];
                break;
            case 'generated-notes': $state['rendered_notes'] = 'not matching complete fragments';
                break;
            case 'existing-next': $state['import_doc'] = $state['approved_doc'];
                break;
            case 'forged-central-notes':
                $state['approved_doc'] = $state['approved_doc']->withReleases([new HistoryRelease('1.0.1', '2026-10-03', null, 'forged notes'), ...array_slice($state['approved_doc']->getReleases(), 1)]);
                $this->refreshApproved($state);
                break;
            case 'forged-prior-history':
                $state['approved_doc'] = $state['approved_doc']->withReleases([$state['approved_doc']->getReleases()[0], new HistoryRelease('1.0.0', '2026-09-01', null, 'silently edited prior notes')]);
                $this->refreshApproved($state);
                break;
        }
        $this->failure($options, $state, $message);
    }

    /** Supplies unsafe modes, lost evidence, partial fragment consumption and tampered historical/generated bytes. */
    public static function evidenceFailures(): iterable
    {
        yield ['metadata-missing', 'lacks generated release evidence'];
        yield ['settings', 'history or settings differ'];
        yield ['central-mode', 'exact regular committed'];
        yield ['central-missing', 'history or settings differ'];
        yield ['central-bytes', 'history or settings differ'];
        yield ['base-ancestry', 'source-base ancestry'];
        yield ['base-central-mode', 'exact regular committed'];
        foreach (['approved-new-fragment', 'approved-nested-fragment', 'approved-hidden-fragment'] as $mutation) {
            yield [$mutation, 'still contains pending Markdown'];
        }
        foreach (['hidden-base-fragment', 'nested-base-fragment', 'outside-base-fragment', 'symbolic-fragment'] as $mutation) {
            yield [$mutation, 'unsafe or noncanonical'];
        }
        yield ['fragment-missing', 'missing from its source base'];
        yield ['fragment-invalid', 'base fragment is invalid'];
        yield ['fragment-pending', 'still exists at the approved'];
        yield ['next-version', 'no section for the computed'];
        yield ['resolution', 'does not resolve a valid'];
        yield ['existing-next', 'already exists in the source-base'];
        foreach (['extra-base-fragment', 'missing-base-fragment', 'fragment-bytes', 'generated-notes', 'forged-central-notes', 'forged-prior-history'] as $mutation) {
            yield [$mutation, 'canonical consolidation'];
        }
    }

    /** The selected repository is required even when all commit bytes otherwise match. */
    public function testMissingRepositoryCannotAuthorizePublication(): void
    {
        $options = new ReleaseOptions('/consumer');
        $state = $this->state($options);
        $this->failure($options, $state, 'selected GitHub repository');
    }

    /** A selected executable template is resolved only after committed mode, exact HEAD and live-byte proof. */
    public function testTrustedCustomTemplateIsResolvedAfterCheckoutAndByteProof(): void
    {
        $options = new ReleaseOptions('/consumer', template: 'custom.php', repository: 'owner/repo');
        $state = $this->state($options);
        self::assertSame('1.0.1', $this->validator($state)->validate($options, self::APPROVED)->version);
        self::assertSame(1, $state['live_reads']);
        self::assertContains('HEAD', $state['resolved_refs']);
        self::assertSame(1, $state['template_resolves']);
    }

    /** Dirty, missing, symbolic, outside-root and moved-checkout custom templates are never executed. */
    #[DataProvider('unsafeCustomTemplates')]
    public function testUnsafeCustomTemplateFailsBeforeResolution(string $path, string $mutation, string $message): void
    {
        $options = new ReleaseOptions('/consumer', template: $path, repository: 'owner/repo');
        $state = $this->state($options);
        if ('head' === $mutation) {
            $state['head'] = str_repeat('c', 40);
        }
        if ('live' === $mutation) {
            $state['live_template'] = 'dirty edited PHP';
        }
        if ('missing' === $mutation) {
            $state['blobs'][self::APPROVED][$path] = null;
        }
        if ('mode' === $mutation) {
            $state['tree'][self::APPROVED][$path] = '120000';
        }
        $this->failure($options, $state, $message);
        self::assertSame(0, $state['template_resolves']);
    }

    /** Supplies unsafe custom selections and exact approved-checkout/source mismatches. */
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

    /** Records refusal diagnostics while asserting that no publication evidence was manufactured. */
    private function failure(ReleaseOptions $options, array &$state, string $message, ?string $sha = null): void
    {
        try {
            $this->validator($state)->validate($options, $sha ?? self::APPROVED);
            self::fail('Invalid committed evidence must fail.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame([], $state['created']);
    }

    /** Builds baseline and approved trees with no generated file in the fragment directory. */
    private function state(ReleaseOptions $options): array
    {
        $base = new HistoryDocument([new HistoryRelease('1.0.0', '2026-09-01', null, 'Preserved previous notes')], 'Original introduction', '[prior]: https://example.test/prior');
        $approved = $base->withReleases([new HistoryRelease('1.0.1', '2026-10-03', null, "Exact  notes\n"), ...$base->getReleases()]);
        $central = $this->encode($approved);
        return [
            'metadata' => ['base_sha' => self::BASE, 'plan_id' => str_repeat('e', 64), 'output_sha256' => hash('sha256', $central), 'options_sha256' => $options->evidenceHash()],
            'resolved' => self::APPROVED, 'head' => self::APPROVED, 'resolved_refs' => [], 'created' => [], 'ancestry_calls' => [], 'read_paths' => [],
            'base_ancestor' => true, 'parser_valid' => true, 'parser_calls' => 0, 'history_parse_calls' => 0, 'tags_calls' => 0, 'current' => '1.0.0', 'next' => '1.0.1', 'impact' => VersionImpact::Patch, 'resolution_errors' => [],
            'base_doc' => $base, 'approved_doc' => $approved, 'import_doc' => null, 'rendered_notes' => "Exact  notes\n", 'rendered_versions' => [], 'rendered_prefix' => null, 'rendered_references' => null,
            'live_template' => '<?php return [];', 'live_reads' => 0, 'template_resolves' => 0, 'resolved_ids' => [], 'reachable' => [], 'outside_fragment' => false,
            'tags' => [['name' => 'v1.0.0', 'sha' => str_repeat('c', 40), 'date' => null, 'date_source' => null], ['name' => 'v9.0.0', 'sha' => str_repeat('d', 40), 'date' => null, 'date_source' => null]],
            'blobs' => [self::BASE => ['CHANGELOG.md' => $this->encode($base), '.changelog/a.md' => 'alpha', '.changelog/b.md' => 'beta'],
                self::APPROVED => ['CHANGELOG.md' => $central, $options->template => '<?php return [];']],
            'tree' => [self::BASE => ['CHANGELOG.md' => '100644', '.changelog/a.md' => '100644', '.changelog/b.md' => '100755', '.changelog/AGENTS.md' => '100644'],
                self::APPROVED => ['CHANGELOG.md' => '100644', '.changelog/AGENTS.md' => '100644', $options->template => '100644']],
        ];
    }

    /** Models a history-only rewrite while retaining pending fragments byte for byte at both commits. */
    private function pendingMaintenanceState(ReleaseOptions $options): array
    {
        $state = $this->state($options);
        foreach (['.changelog/a.md', '.changelog/b.md'] as $path) {
            $state['tree'][self::APPROVED][$path] = $state['tree'][self::BASE][$path];
            $state['blobs'][self::APPROVED][$path] = $state['blobs'][self::BASE][$path];
        }
        $state['approved_doc'] = new HistoryDocument($state['base_doc']->getReleases(), 'Formatted introduction', $state['base_doc']->getReferences());
        $this->refreshApproved($state);
        return $state;
    }

    /** Encodes a deterministic test-only history presentation so every prior and new byte participates in comparison. */
    private function encode(HistoryDocument $document): string
    {
        return json_encode([$document->getPrefix(), array_map(static fn(HistoryRelease $release): array => [$release->getVersion(), $release->getDate(), $release->getBody()], $document->getReleases()), $document->getReferences()], JSON_THROW_ON_ERROR);
    }

    /** Rebinds attacker-selected complete output bytes to their hash to exercise semantic checks beyond hash integrity. */
    private function refreshApproved(array &$state): void
    {
        $state['blobs'][self::APPROVED]['CHANGELOG.md'] = $this->encode($state['approved_doc']);
        $state['metadata']['output_sha256'] = hash('sha256', $state['blobs'][self::APPROVED]['CHANGELOG.md']);
    }

    /** Replaces every Git, file, template and domain boundary without process, filesystem or network access. */
    private function validator(array &$state): PublicationEvidenceValidator
    {
        $git = $this->createMock(GitRepositoryInterface::class);
        $git->expects(self::never())->method('commitFragment');
        $git->method('resolveRef')->willReturnCallback(static function (string $directory, string $reference) use (&$state): string {
            self::assertSame('/consumer', $directory);
            $state['resolved_refs'][] = $reference;
            return 'HEAD' === $reference ? $state['head'] : $state['resolved'];
        });
        $git->method('releaseMetadata')->willReturnCallback(static function (string $directory, string $reference, string $path) use (&$state): ?array {
            self::assertSame('/consumer', $directory);
            self::assertSame($state['resolved'], $reference);
            self::assertSame('CHANGELOG.md', $path);
            return $state['metadata'];
        });
        $git->method('filesAt')->willReturnCallback(static function (string $directory, string $sha, string $path) use (&$state): array {
            self::assertSame('/consumer', $directory);
            if (self::BASE === $sha && '.changelog' === $path && $state['outside_fragment']) {
                return [['path' => 'outside.md', 'mode' => '100644']];
            }
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
            $state['read_paths'][] = $path;
            return $state['blobs'][$sha][$path] ?? null;
        });
        $git->method('isAncestor')->willReturnCallback(static function (string $directory, string $ancestor, string $descendant) use (&$state): bool {
            $state['ancestry_calls'][] = [$ancestor, $descendant];
            return self::BASE === $ancestor ? $state['base_ancestor'] : $ancestor === str_repeat('c', 40);
        });
        $git->method('tags')->willReturnCallback(static function (string $directory) use (&$state): array {
            ++$state['tags_calls'];
            return $state['tags'];
        });
        $parser = $this->createStub(ChangesetParserInterface::class);
        $parser->method('parse')->willReturnCallback(static function (string $path, string $contents) use (&$state): ChangesetParseResult {
            ++$state['parser_calls'];
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
        $importer->method('import')->willReturnCallback(static function (HistoryDocument $document, ReleaseOptions $options, TemplateInterface $template, array $tags) use (&$state): HistoryImportResult {
            self::assertSame($state['base_doc'], $document);
            self::assertSame($state['reachable'], $tags);
            return new HistoryImportResult($state['import_doc'] ?? $document, [], $state['current']);
        });
        $history = $this->createStub(HistoryCodecInterface::class);
        $history->method('parse')->willReturnCallback(static function (string $contents, TemplateInterface $template) use (&$state): HistoryDocument {
            ++$state['history_parse_calls'];
            return $contents === ($state['blobs'][self::BASE]['CHANGELOG.md'] ?? '') ? $state['base_doc'] : $state['approved_doc'];
        });
        $history->method('notes')->willReturnCallback(static fn(HistoryDocument $doc, string $version): string => $doc->getRelease($version)->getBody());
        $history->method('render')->willReturnCallback(function (HistoryDocument $doc, TemplateInterface $template, bool $preserve) use (&$state): string {
            self::assertTrue($preserve);
            $state['rendered_versions'] = array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $doc->getReleases());
            $state['rendered_prefix'] = $doc->getPrefix();
            $state['rendered_references'] = $doc->getReferences();
            return $this->encode($doc);
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
            return array_map(static fn(Changeset $change): string => $change->description, $changes) === ['alpha', 'beta'] ? $state['rendered_notes'] : 'Notes from a different fragment set';
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
        $evidence = $this->createStub(PublicationEvidenceFactoryInterface::class);
        $evidence->method('create')->willReturnCallback(static function (string $sha, ?string $version, ?string $tag, string $notes, ?string $repository) use (&$state): PublicationEvidence {
            $state['created'][] = [$sha, $version, $tag, $notes, $repository];
            return new PublicationEvidence($sha, $version, $tag, $notes, $repository);
        });
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message, ?Throwable $previous = null): InvalidArgumentException => new InvalidArgumentException($message, previous: $previous));
        return new PublicationEvidenceValidator($git, $history, $parser, $versions, $importer, $notes, $templates, $paths, $files, $releases, $evidence, $exceptions);
    }
}
