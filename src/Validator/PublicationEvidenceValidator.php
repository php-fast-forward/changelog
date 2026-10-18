<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Validator;

use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitCommitTreeReaderInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\GitHub\GitHubClientInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryReferenceBuilder;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Release\Reference\PullRequestReferenceResolverInterface;
use FastForward\Changelog\Release\ReleaseHistoryConsolidatorInterface;
use FastForward\Changelog\Release\ReleaseMetadataParser;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\Renderer\ReleaseNotesRendererInterface;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Version\NextVersionResolverInterface;
use FastForward\Changelog\Version\VersionBaselineResolver;
use FastForward\Changelog\Version\VersionBaselineResolverInterface;
use InvalidArgumentException;
use RuntimeException;

/** Recomputes release semantics from committed fragment bytes without relying on a generated repository file. */
final readonly class PublicationEvidenceValidator implements MergedPublicationEvidenceValidatorInterface
{
    private ReleaseMetadataParser $metadata;

    /**
     * Injects read-only Git/domain proof and guarded custom-template boundaries without I/O.
     *
     * @param GitRepositoryInterface                $git          Replaceable repository boundary for immutable blobs, identities, ancestry and
     *                                                            local Git operations.
     * @param HistoryCodecInterface                 $history      Parser and renderer that preserves historical Markdown and its exact
     *                                                            section boundaries.
     * @param ChangesetParserInterface              $parser       Schema parser that retains per-fragment diagnostics instead of silently
     *                                                            dropping invalid input.
     * @param NextVersionResolverInterface          $versions     Pure semantic-version resolver for the complete accepted fragment
     *                                                            set.
     * @param HistoryImporterInterface              $importer     Historical release importer and stable tag precedence resolver.
     * @param ReleaseNotesRendererInterface         $notes        Renderer for deterministic user-facing notes from the accepted
     *                                                            fragment values.
     * @param TemplateResolverInterface             $templates    Resolver for built-in or explicitly verified consumer presentation
     *                                                            templates.
     * @param PackagePathResolverInterface          $paths        Consumer-root path resolver used to constrain managed file access.
     * @param ManagedFileStoreInterface             $files        Replaceable managed-file reader used to compare checkout bytes with
     *                                                            committed evidence.
     * @param HistoryReleaseFactoryInterface        $releases     Constructor for immutable history sections with explicit date
     *                                                            provenance and endings.
     * @param PullRequestReferenceResolverInterface $pullRequests Recomputes opted-in PR provenance from the exact committed source before accepting release notes.
     * @param ReleaseHistoryConsolidatorInterface   $consolidator Canonical promotion operation that preserves already
     *                                                            published historical Markdown.
     * @param ?GitHubClientInterface                $github       Optional immutable-object API reader; contextual merged-head proof is
     *                                                            unavailable without it.
     * @param ?ReleaseMetadataParser                $metadata     Optional strict commit-trailer parser or contextual metadata proven by
     *                                                            the approved PR head.
     * @param HistoryReferenceBuilder               $references   Pure concrete-release link completion shared with release planning.
     * @param VersionBaselineResolverInterface      $baselines    Shared stable maintained-version selection that does not imply prior publication.
     */
    public function __construct(
        private GitRepositoryInterface $git,
        private HistoryCodecInterface $history,
        private ChangesetParserInterface $parser,
        private NextVersionResolverInterface $versions,
        private HistoryImporterInterface $importer,
        private ReleaseNotesRendererInterface $notes,
        private TemplateResolverInterface $templates,
        private PackagePathResolverInterface $paths,
        private ManagedFileStoreInterface $files,
        private HistoryReleaseFactoryInterface $releases,
        private ReleaseHistoryConsolidatorInterface $consolidator,
        private PullRequestReferenceResolverInterface $pullRequests,
        private ?GitHubClientInterface $github = null,
        ?ReleaseMetadataParser $metadata = null,
        private HistoryReferenceBuilder $references = new HistoryReferenceBuilder(),
        private VersionBaselineResolverInterface $baselines = new VersionBaselineResolver(),
    ) {
        $this->metadata = $metadata ?? new ReleaseMetadataParser();
    }

    /**
     * Validates exact blobs, complete base inventory, ancestry, recomputed impact/version and central note bytes.
     *
     * @param  ReleaseOptions           $options     Trusted consumer paths, rendering settings and repository selection for this
     *                                               operation.
     * @param  string                   $approvedSha Complete approved commit identity whose exact committed transaction is required.
     * @return PublicationEvidence      Reconstructed approved-commit publication evidence, with no version for supported
     *                                  maintenance.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    public function validate(ReleaseOptions $options, string $approvedSha): PublicationEvidence
    {
        return $this->prove($options, $approvedSha);
    }

    /**
     * Obtains the head only through immutable API objects, including after its branch was deleted.
     *
     * No head checkout, local head object, Git credential or executable PR content is required.
     *
     * @param  ReleaseOptions           $options  Trusted consumer paths, rendering settings and repository selection for this
     *                                            operation.
     * @param  string                   $mergeSha Exact approved merge commit that will be validated and, if applicable, tagged.
     * @param  string                   $headSha  Immutable generated PR head whose ownership and committed output must be proved.
     * @return PublicationEvidence      Evidence reconstructed at the merge from the independently approved, identical-tree
     *                                  PR head.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    public function validateMerged(
        ReleaseOptions $options,
        string $mergeSha,
        string $headSha,
    ): PublicationEvidence {
        if (null === $this->github || !$this->git instanceof GitCommitTreeReaderInterface
            || !GitHubEvidence::repository($options->repository)
            || !GitHubEvidence::sha($mergeSha) || !GitHubEvidence::sha($headSha)) {
            throw new InvalidArgumentException(
                'Merged publication requires exact commits and immutable API/tree proof capabilities.',
            );
        }
        $root = '/repos/' . $options->repository;
        $head = $this->github->request('GET', $root . '/commits/' . $headSha);
        $merge = $this->github->request('GET', $root . '/git/commits/' . $mergeSha);
        $tree = $this->git->treeSha($options->workingDirectory, $mergeSha);

        if (($head['sha'] ?? null) !== $headSha || ($merge['sha'] ?? null) !== $mergeSha
            || !GitHubEvidence::sha($tree)
            || ($head['commit']['tree']['sha'] ?? null) !== $tree
            || ($merge['tree']['sha'] ?? null) !== $tree
            || !is_string($head['commit']['message'] ?? null)) {
            throw new InvalidArgumentException(
                'The immutable approved head and merge must have identical complete Git trees.',
            );
        }
        $data = $this->metadata->parse($head['commit']['message']);

        if (null === $data) {
            throw new InvalidArgumentException('The approved PR head lacks generated release evidence.');
        }
        $comparison = $this->github->request('GET', $root . '/compare/' . $data['base_sha'] . '...' . $headSha);

        if (($comparison['merge_base_commit']['sha'] ?? null) !== $data['base_sha']
            || !in_array($comparison['status'] ?? null, ['ahead', 'identical'], true)) {
            throw new InvalidArgumentException('The approved PR head lacks its generated source-base ancestry.');
        }

        return $this->prove($options, $mergeSha, $data);
    }

    /**
     * Recomputes every domain invariant on the target; contextual metadata never changes the tagged commit.
     *
     * @param  ReleaseOptions           $options     Trusted consumer paths, rendering settings and repository selection for this
     *                                               operation.
     * @param  string                   $approvedSha Complete approved commit identity whose exact committed transaction is required.
     * @param  ?array                   $metadata    Strict contextual trailers from an approved identical-tree head, or null for target-local
     *                                               evidence.
     * @return PublicationEvidence      Exact approved release identity and central note bytes, or versionless supported
     *                                  maintenance evidence.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    private function prove(
        ReleaseOptions $options,
        string $approvedSha,
        ?array $metadata = null,
    ): PublicationEvidence {
        if (1 !== preg_match('~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D', $approvedSha)
            || $this->git->resolveRef($options->workingDirectory, $approvedSha) !== $approvedSha) {
            throw new InvalidArgumentException('Publication requires the complete exact approved commit SHA.');
        }
        $central = $this->git->readFileAt($options->workingDirectory, $approvedSha, $options->changelogFile);

        if (null !== $central) {
            $this->regularFile($options, $approvedSha, $options->changelogFile);
        }
        $data = $metadata ?? $this->git->releaseMetadata(
            $options->workingDirectory,
            $approvedSha,
            $options->changelogFile,
        );

        if (null === $data) {
            throw new InvalidArgumentException(
                'The approved commit lacks generated release evidence. Preserve the four Changelog trailers when committing or squashing a consolidation.',
            );
        }

        if (null === $central || hash('sha256', $central) !== $data['output_sha256']
            || $options->evidenceHash() !== $data['options_sha256']) {
            throw new InvalidArgumentException(
                'The approved central history or settings differ from the generated commit evidence.',
            );
        }
        $base = $data['base_sha'];

        if (null === $options->repository || ! $this->git->isAncestor(
            $options->workingDirectory,
            $base,
            $approvedSha,
        )) {
            throw new InvalidArgumentException(
                'Publication requires a selected GitHub repository and the generated source-base ancestry.',
            );
        }
        $baseCentral = $this->git->readFileAt($options->workingDirectory, $base, $options->changelogFile);

        if (null !== $baseCentral) {
            $this->regularFile($options, $base, $options->changelogFile);
        }
        $selected = $this->pendingFragments($options, $base, true);
        $remaining = $this->pendingFragments($options, $approvedSha, false);

        if ([] !== $remaining) {
            $this->assertRetainedFragments($options, $base, $approvedSha, $selected, $remaining);
        }
        $reachable = $this->reachableTags($options, $base);
        $current = $this->importer->currentVersion($reachable, $options->tagPrefix);
        $this->trustedTemplate($options, $approvedSha);
        $template = $this->templates->resolve($options);
        $approved = $this->history->parse($central, $template);
        $source = $this->history->parse($baseCentral ?? '', $template);
        $current = $this->baselines->resolve($source, $current);
        $document = $this->backfilledHistory($source, $approved, $options, $template, $reachable);

        if ([] !== $remaining || [] === $selected) {
            if ((null !== $document && $central === $this->history->render($document, $template, true))
                || $central === $this->history->render($source, $template, false)) {
                return new PublicationEvidence($approvedSha, null, null, '', $options->repository);
            }

            throw new InvalidArgumentException(
                'The approved history is not supported backfill or format maintenance; an incomplete release must consume every source fragment.',
            );
        }

        if (null === $document) {
            throw new InvalidArgumentException(
                'The approved release is missing a historical section for a reachable stable Git tag.',
            );
        }
        $changesets = $this->consumedChangesets($options, $base, $approvedSha, $selected);
        if ($options->resolvePullRequests) {
            $changesets = $this->pullRequests->resolve($options, $base, $changesets);
        }
        $resolved = $this->versions->resolve($current, $changesets);

        if (! $resolved->isValid()) {
            throw new InvalidArgumentException('The committed fragment set does not resolve a valid release version.');
        }
        $version = $resolved->nextVersion;
        $section = $approved->getRelease($version);

        if (null === $section) {
            throw new InvalidArgumentException('The approved history has no section for the computed release version.');
        }
        $rendered = $this->notes->render($changesets, $template, $options->repository);

        if (null !== $document->getRelease($version)) {
            throw new InvalidArgumentException('The computed release already exists in the source-base history.');
        }
        $promoted = $this->consolidator->promote($document, $version, $section->getDate(), $rendered, $template);
        $expected = $this->history->render($promoted, $template, true);

        // Already approved releases can predate link completion; both accepted forms are reconstructed in full.
        if ($central !== $expected && $central !== $this->history->render(
            $this->references->complete($promoted, $options->repository, $options->tagPrefix),
            $template,
            true,
        )) {
            throw new InvalidArgumentException(
                'The approved history is not the canonical consolidation of every source-base fragment and exact prior history.',
            );
        }
        $centralNotes = $this->history->notes($approved, $version);

        return new PublicationEvidence(
            sha: $approvedSha,
            version: $version,
            tag: $options->tagPrefix . $version,
            notes: $centralNotes,
            repository: $options->repository,
        );
    }

    /**
     * Requires maintenance to retain the complete pending inventory and every original fragment byte.
     *
     * @param  ReleaseOptions           $options     Trusted consumer paths, rendering settings and repository selection for this
     *                                               operation.
     * @param  string                   $base        Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @param  string                   $approvedSha Complete approved commit identity whose exact committed transaction is required.
     * @param  list<string>             $selected    Sorted complete source-base fragment inventory selected for consolidation.
     * @param  list<string>             $remaining   Sorted pending fragment inventory still present at the approved target commit.
     * @return void                     No value; every retained pending path and blob must equal the source-base inventory.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    private function assertRetainedFragments(
        ReleaseOptions $options,
        string $base,
        string $approvedSha,
        array $selected,
        array $remaining,
    ): void {
        if ($selected !== $remaining) {
            throw new InvalidArgumentException(
                'The approved consolidation still contains pending Markdown fragments from an incomplete or unexpected selection.',
            );
        }

        foreach ($remaining as $path) {
            $before = $this->git->readFileAt($options->workingDirectory, $base, $path);

            if (null === $before || $before !== $this->git->readFileAt(
                $options->workingDirectory,
                $approvedSha,
                $path,
            )) {
                throw new InvalidArgumentException(
                    'Maintenance requires every pending fragment to retain its exact source-base bytes: ' . $path,
                );
            }
        }
    }

    /**
     * Selects only tags whose commits belong to the approved source-base ancestry.
     *
     * @param  ReleaseOptions                                                       $options Trusted consumer paths, rendering settings and repository selection for this
     *                                                                                       operation.
     * @param  string                                                               $base    Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @return list<array{name:string,sha:string,date:?string,date_source:?string}> Tag records whose commits are
     *                                                                              ancestors of the exact source base.
     * @throws RuntimeException                                                     When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                                                              evidence.
     */
    private function reachableTags(ReleaseOptions $options, string $base): array
    {
        $reachable = [];

        foreach ($this->git->tags($options->workingDirectory) as $tag) {
            if ($this->git->isAncestor($options->workingDirectory, $tag['sha'], $base)) {
                $reachable[] = $tag;
            }
        }

        return $reachable;
    }

    /**
     * Parses each exact source blob and proves its deletion before computing any release semantics.
     *
     * @param  ReleaseOptions           $options     Trusted consumer paths, rendering settings and repository selection for this
     *                                               operation.
     * @param  string                   $base        Immutable source-base commit used for ancestry, inventory and freshness comparisons.
     * @param  string                   $approvedSha Complete approved commit identity whose exact committed transaction is required.
     * @param  list<string>             $selected    Sorted complete source-base fragment inventory selected for consolidation.
     * @return list<Changeset>          Parsed source-base fragments whose exact paths have all disappeared from the target.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    private function consumedChangesets(
        ReleaseOptions $options,
        string $base,
        string $approvedSha,
        array $selected,
    ): array {
        $changesets = [];

        foreach ($selected as $path) {
            $contents = $this->git->readFileAt($options->workingDirectory, $base, $path);

            if (null === $contents) {
                throw new InvalidArgumentException('A consumed fragment is missing from its source base: ' . $path);
            }
            $result = $this->parser->parse(
                $this->paths->absolutePath($path, $options->workingDirectory),
                $contents,
            );

            if (! $result->isValid()) {
                throw new InvalidArgumentException(
                    'A consumed base fragment is invalid: ' . $path . ' ' . implode('; ', $result->errors),
                );
            }

            if (null !== $this->git->readFileAt($options->workingDirectory, $approvedSha, $path)) {
                throw new InvalidArgumentException('A consumed fragment still exists at the approved commit: ' . $path);
            }
            $changesets[] = $result->changeset;
        }

        return $changesets;
    }

    /**
     * Reuses approved imported snapshots while every pre-existing section retains its exact source-base presentation.
     *
     * @param  HistoryDocument                                                      $source   Parsed source-base history whose published sections must retain their exact
     *                                                                                        presentation.
     * @param  HistoryDocument                                                      $approved Parsed approved history used only after its immutable transaction evidence has
     *                                                                                        been checked.
     * @param  ReleaseOptions                                                       $options  Trusted consumer paths, rendering settings and repository selection for this
     *                                                                                        operation.
     * @param  TemplateInterface                                                    $template Already selected presentation contract, including validated
     *                                                                                        executable-template policy.
     * @param  list<array{name:string,sha:string,date:?string,date_source:?string}> $tags     Reachable Git tag records with
     *                                                                                        immutable commits and
     *                                                                                        independently known date
     *                                                                                        provenance.
     * @return ?HistoryDocument                                                     Reconstructed complete prior history, or null when an expected imported section is
     *                                                                              absent.
     * @throws RuntimeException                                                     When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                                                              evidence.
     */
    private function backfilledHistory(
        HistoryDocument $source,
        HistoryDocument $approved,
        ReleaseOptions $options,
        TemplateInterface $template,
        array $tags,
    ): ?HistoryDocument {
        if ('tags' === $options->source) {
            // This explicit importer mode never reads GitHub and preserves deterministic placeholders and tag dates.
            return $this->importer->import($source, $options, $template, $tags)->document;
        }
        $tagged = [];

        foreach ($tags as $tag) {
            if (str_starts_with($tag['name'], $options->tagPrefix)) {
                $version = substr($tag['name'], strlen($options->tagPrefix));

                if ($this->stable($version)) {
                    $tagged[$version] = $tag;
                }
            }
        }
        uksort($tagged, fn(string $left, string $right): int => $left === $right ? 0
            : ($this->higher($left, $right, $options->tagPrefix, $tagged[$left]['sha']) ? -1 : 1));
        $releases = $source->getReleases();

        foreach ($tagged as $version => $tag) {
            if (null !== $source->getRelease($version)) {
                continue;
            }
            $snapshot = $approved->getRelease($version);

            if (null === $snapshot) {
                return null;
            }
            $section = $this->releases->create(
                $version,
                $snapshot->getDate(),
                $snapshot->getDateSource(),
                $snapshot->getBody(),
            );
            $position = count($releases);

            foreach ($releases as $index => $existing) {
                if ($this->stable($existing->getVersion())
                    && $this->higher($version, $existing->getVersion(), $options->tagPrefix, $tag['sha'])) {
                    $position = $index;

                    break;
                }
            }
            array_splice($releases, $position, 0, [$section]);
        }

        return $source->withReleases($releases);
    }

    /**
     * Accepts exactly the stable identities that the shared historical importer accepts.
     *
     * @param  string $version Semantic version selected for comparison, planning or publication.
     * @return bool   Whether the version belongs to the importer-supported stable SemVer grammar.
     */
    private function stable(string $version): bool
    {
        return 1 === preg_match(
            '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/',
            $version,
        );
    }

    /**
     * Delegates ordering to the pure shared tag baseline, retaining large numeric components and build tie semantics.
     *
     * @param  string $left   Candidate stable version whose precedence is compared with the other version.
     * @param  string $right  Stable version against which the candidate precedence is compared.
     * @param  string $prefix Exact stable-version Git tag prefix selected by the consumer.
     * @param  string $sha    Complete immutable Git object identity selected for this evidence or result.
     * @return bool   Whether the candidate wins the shared stable-tag ordering, including large components and build
     *                ties.
     */
    private function higher(
        string $left,
        string $right,
        string $prefix,
        string $sha,
    ): bool {
        return $left !== $right && $left === $this->importer->currentVersion([
            ['name' => $prefix . $left, 'sha' => $sha, 'date' => null, 'date_source' => null],
            ['name' => $prefix . $right, 'sha' => $sha, 'date' => null, 'date_source' => null],
        ], $prefix);
    }

    /**
     * Inventories the complete canonical pending scope while retaining safe maintenance without consumption.
     *
     * @param  ReleaseOptions           $options Trusted consumer paths, rendering settings and repository selection for this
     *                                           operation.
     * @param  string                   $sha     Complete immutable Git object identity selected for this evidence or result.
     * @param  bool                     $source  Whether refusal diagnostics describe the original source base or approved target inventory.
     * @return list<string>             Sorted complete direct canonical fragment paths in the selected committed tree.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    private function pendingFragments(ReleaseOptions $options, string $sha, bool $source): array
    {
        $selected = [];

        foreach ($this->git->filesAt(
            $options->workingDirectory,
            $sha,
            $options->fragmentDirectory,
        ) as $entry) {
            $path = $entry['path'];

            if (! str_ends_with($path, '.md') || $path === $options->fragmentDirectory . '/AGENTS.md') {
                continue;
            }
            $prefix = $options->fragmentDirectory . '/';

            if (! str_starts_with($path, $prefix)
                || 1 !== preg_match(
                    ChangesetParserInterface::FILENAME_PATTERN,
                    substr($path, strlen($prefix)),
                )
                || ! in_array($entry['mode'], ['100644', '100755'], true)) {
                throw new InvalidArgumentException(($source
                    ? 'The source base contains an unsafe or noncanonical fragment: '
                    : 'The approved consolidation still contains pending Markdown fragments that are unsafe or noncanonical: ') . $path);
            }
            $selected[] = $path;
        }
        sort($selected, SORT_STRING);

        return $selected;
    }

    /**
     * Requires an exact regular blob, refusing Git symlink/submodule entries before any execution.
     *
     * @param  ReleaseOptions           $options Trusted consumer paths, rendering settings and repository selection for this
     *                                           operation.
     * @param  string                   $sha     Complete immutable Git object identity selected for this evidence or result.
     * @param  string                   $path    Canonical repository-relative managed path selected for this operation.
     * @return void                     No value; the selected committed entry must be one exact regular blob.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    private function regularFile(ReleaseOptions $options, string $sha, string $path): void
    {
        $entries = $this->git->filesAt($options->workingDirectory, $sha, $path);

        if (1 !== count($entries) || $entries[0]['path'] !== $path || ! in_array(
            $entries[0]['mode'],
            ['100644', '100755'],
            true,
        )) {
            throw new InvalidArgumentException('Publication requires an exact regular committed file: ' . $path);
        }
    }

    /**
     * Pins explicit executable template selection to an unchanged tracked blob in the approved checkout.
     *
     * @param  ReleaseOptions           $options Trusted consumer paths, rendering settings and repository selection for this
     *                                           operation.
     * @param  string                   $sha     Complete immutable Git object identity selected for this evidence or result.
     * @return void                     No value; executable templates must match the selected approved checkout blob before resolution.
     * @throws InvalidArgumentException When committed history, scope, settings, ancestry or transaction evidence
     *                                  violates the publication contract.
     * @throws RuntimeException         When an injected Git, managed-file, template or API boundary cannot supply the required
     *                                  evidence.
     */
    private function trustedTemplate(ReleaseOptions $options, string $sha): void
    {
        if ('keep-a-changelog' === $options->template) {
            return;
        }
        $path = $options->template;

        if ('' === $path || $this->paths->isAbsolute($path) || str_contains(
            $path,
            '\\',
        ) || str_contains(
            $path,
            "\0",
        )
            || [] !== array_intersect(explode('/', $path), ['', '.', '..'])
            || $this->git->resolveRef($options->workingDirectory, 'HEAD') !== $sha) {
            throw new InvalidArgumentException(
                'Publication custom templates require a canonical tracked path in the approved checkout.',
            );
        }
        $this->regularFile($options, $sha, $path);
        $committed = $this->git->readFileAt($options->workingDirectory, $sha, $path);

        if (null === $committed || $this->files->read(
            $this->paths->absolutePath($path, $options->workingDirectory),
        ) !== $committed) {
            throw new InvalidArgumentException(
                'The selected custom template differs from the exact approved committed blob.',
            );
        }
    }
}
