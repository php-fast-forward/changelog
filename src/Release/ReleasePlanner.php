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

namespace FastForward\Changelog\Release;

use DateTimeZone;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryReferenceBuilder;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Release\Factory\ReleasePlanFactoryInterface;
use FastForward\Changelog\Release\Reference\PullRequestReferenceResolverInterface;
use FastForward\Changelog\Release\Renderer\ReleaseNotesRendererInterface;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use FastForward\Changelog\Validator\ReleaseInputEvidenceValidatorInterface;
use FastForward\Changelog\Version\NextVersionResolverInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use RuntimeException;

/** Collects exact validated inputs before selecting one deterministic release transaction. */
final readonly class ReleasePlanner implements ReleasePlannerInterface
{
    /**
     * Composes domain rules and replaceable external boundaries; construction performs no I/O.
     *
     * @param PackagePathResolverInterface           $paths         Resolves explicit consumer paths without filesystem observation.
     * @param ManagedFileStoreInterface              $files         Reads and writes guarded managed bytes through an injected filesystem boundary.
     * @param GitRepositoryInterface                 $git           Observes repository identities, committed bytes and scoped operations through an injected boundary.
     * @param HistoryCodecInterface                  $history       Parses, renders and extracts exact maintained Markdown through the pure history boundary.
     * @param HistoryImporterInterface               $importer      Resolves supported stable tags and imports only missing historical versions.
     * @param TemplateResolverInterface              $templates     Resolves built-in or explicitly selected trusted PHP presentation through injected boundaries.
     * @param ChangesetValidatorInterface            $validator     Checks the complete fragment inventory, canonical paths and accepted bytes without fail-fast loss.
     * @param NextVersionResolverInterface           $versions      Calculates the numeric next release from the greatest persisted pending-fragment impact.
     * @param ReleaseNotesRendererInterface          $notesRenderer Renders canonical category-grouped release notes without rewriting authored descriptions.
     * @param ReleaseHistoryConsolidatorInterface    $consolidator  Promotes legacy pending descriptions and new fragment notes into one concrete release.
     * @param ClockInterface                         $clock         Injected PSR-20 clock used only when assigning the new release's calendar date.
     * @param DateTimeZone                           $timezone      Explicit timezone used with the injected clock, independent of the host's default timezone.
     * @param ReceiptCodecInterface                  $receipts      Validates and serializes deterministic private recovery evidence, including its content-bound identity.
     * @param ReleasePlanFactoryInterface            $plans         Builds or restores immutable transactions from exact accepted inputs and resulting history bytes.
     * @param ReleaseInputEvidenceValidatorInterface $inputs        Verifies planned or selected-template bytes against the approved committed source evidence.
     * @param ReleaseJournalPathResolverInterface    $journals      Selects private recovery storage scoped to the consumer project and managed paths.
     * @param PullRequestReferenceResolverInterface  $pullRequests  Proves optional PR references against immutable fragment origins without modifying authored files.
     * @param HistoryReferenceBuilder                $references    Completes missing concrete release links without altering historical Markdown or observing external state.
     */
    public function __construct(
        private PackagePathResolverInterface $paths,
        private ManagedFileStoreInterface $files,
        private GitRepositoryInterface $git,
        private HistoryCodecInterface $history,
        private HistoryImporterInterface $importer,
        private TemplateResolverInterface $templates,
        private ChangesetValidatorInterface $validator,
        private NextVersionResolverInterface $versions,
        private ReleaseNotesRendererInterface $notesRenderer,
        private ReleaseHistoryConsolidatorInterface $consolidator,
        private ClockInterface $clock,
        private DateTimeZone $timezone,
        private ReceiptCodecInterface $receipts,
        private ReleasePlanFactoryInterface $plans,
        private ReleaseInputEvidenceValidatorInterface $inputs,
        private ReleaseJournalPathResolverInterface $journals,
        private PullRequestReferenceResolverInterface $pullRequests,
        private HistoryReferenceBuilder $references = new HistoryReferenceBuilder(),
    ) {}

    /**
     * Reuses the saved transaction after partial application instead of incrementing it again.
     * Backfill and format MUST NOT consume pending fragments or create a new version.
     *
     * @param ReleaseOptions $options   Validated consumer paths, presentation settings and source-selection policy.
     * @param string         $operation Requested shared operation; version, backfill and format have separate write semantics.
     *
     * @return ReleasePlan Immutable release or maintenance transaction with exact accepted inputs and resulting bytes.
     *
     * @throws \InvalidArgumentException   When the operation, pending fragment schema or next-version inputs are invalid.
     * @throws \RuntimeException           When approved source bytes, publication baseline or recovery evidence cannot be reconciled.
     * @throws PendingPublicationException When maintained stable history still awaits its reachable published tag.
     */
    public function plan(ReleaseOptions $options, string $operation = 'version'): ReleasePlan
    {
        if (! in_array($operation, ['version', 'backfill', 'format'], true)) {
            throw new InvalidArgumentException('Operation must be version, backfill or format.');
        }
        $changelogPath = $this->paths->absolutePath($options->changelogFile, $options->workingDirectory);
        $fragmentPath = $this->paths->absolutePath($options->fragmentDirectory, $options->workingDirectory);
        $receiptPath = $this->journals->resolve($options);
        $original = $this->files->read($changelogPath);
        $receiptBytes = $this->files->read($receiptPath);
        [$baseSha, $tags] = $this->repositoryContext($options);

        if (null !== $receiptBytes) {
            $receipt = $this->receipts->decode($receiptBytes);
            if (null !== $receipt->data['next_version'] && 'version' !== $operation) {
                throw new RuntimeException('Recover the interrupted release before maintaining its history.');
            }
            $inventory = null === $receipt->data['next_version'] ? null : $this->inventory($fragmentPath);
            $this->assertResumable($options, $receipt, $original, $inventory);
            $this->inputs->validateTemplate($options, $receipt->data['base_sha']);

            return $this->plans->resume($options, $receipt, $changelogPath, $original, $receiptPath, $receiptBytes);
        }
        $this->inputs->validateTemplate($options, $baseSha);
        $template = $this->templates->resolve($options);
        $document = $this->history->parse($original ?? '', $template);
        $inventory = 'version' === $operation ? $this->inventory($fragmentPath) : null;
        $currentVersion = $this->importer->currentVersion($tags, $options->tagPrefix);
        $missing = [];
        if ('format' !== $operation) {
            $imported = $this->importer->import($document, $options, $template, $tags);
            $document = $imported->document;
            $currentVersion = $imported->currentVersion;
            $missing = $imported->missingVersions;
        }
        $next = null;
        $impact = null;
        $consumed = [];
        $notes = '';
        if (null !== $inventory && [] !== $inventory->changesets) {
            [$document, $next, $impact, $consumed, $notes] = $this->promoteFragments(
                $options,
                $document,
                $inventory,
                $currentVersion,
                $tags,
                $template,
                $baseSha,
            );
        }

        $changed = 'format' === $operation || null !== $next || [] !== $missing;
        $contents = $changed ? $this->history->render(
            $document,
            $template,
            'format' !== $operation,
        ) : ($original ?? '');
        if (null !== $next) {
            $notes = $this->history->notes($this->history->parse($contents, $template), $next);
        }

        return $this->plans->create(
            $options,
            $baseSha,
            $currentVersion,
            $next,
            $impact,
            $consumed,
            $missing,
            $changelogPath,
            $original,
            $contents,
            $notes,
            $receiptPath,
            $receiptBytes,
        );
    }

    /**
     * Resolves the checked-out base and only tags reachable from that exact commit.
     *
     * @param ReleaseOptions $options Working directory, selected base ref and tag configuration.
     *
     * @return array{0:?string,1:list<array<string,mixed>>} Base commit and reachable tags, or an empty local context.
     */
    private function repositoryContext(ReleaseOptions $options): array
    {
        $baseSha = null;
        $tags = [];
        if ($this->git->isRepository($options->workingDirectory)) {
            $baseSha = $this->git->resolveRef($options->workingDirectory, $options->baseRef);
            if ($baseSha !== $this->git->resolveRef($options->workingDirectory)) {
                throw new RuntimeException('Check out the selected base revision before planning a release.');
            }
            $tags = array_values(array_filter(
                $this->git->tags($options->workingDirectory),
                fn(array $tag): bool => $this->git->isAncestor($options->workingDirectory, $tag['sha'], $baseSha),
            ));
        }

        return [$baseSha, $tags];
    }

    /**
     * Calculates one release and promotes only the validated pending fragment set.
     *
     * @param ReleaseOptions            $options        Repository links, tag prefix and release settings.
     * @param HistoryDocument           $document       Imported maintained history before promotion.
     * @param ValidationReport          $inventory      Accepted fragment records and exact content hashes.
     * @param string                    $currentVersion Latest observed stable release baseline.
     * @param list<array<string,mixed>> $tags           Reachable tags used to prove the published baseline.
     * @param TemplateInterface         $template       Presentation used for notes and structural headings.
     * @param ?string                   $baseSha        Complete selected source commit, or null outside a Git repository.
     *
     * @return array{0:HistoryDocument,1:string,2:string,3:array<string,string>,4:string} Promoted history, next version, impact, consumed hashes and notes.
     */
    private function promoteFragments(
        ReleaseOptions $options,
        HistoryDocument $document,
        ValidationReport $inventory,
        string $currentVersion,
        array $tags,
        TemplateInterface $template,
        ?string $baseSha,
    ): array {
        $this->assertPublishedHistory(
            $document,
            $currentVersion,
            in_array($options->tagPrefix . $currentVersion, array_column($tags, 'name'), true),
        );
        $resolved = $this->versions->resolve($currentVersion, $inventory->changesets);
        if (! $resolved->isValid()) {
            throw new InvalidArgumentException(
                'Cannot calculate the next version: ' . implode('; ', $resolved->errors),
            );
        }
        $next = $resolved->nextVersion;
        if (null !== $document->getRelease($next)) {
            throw new RuntimeException(
                'The next version already has a local section without a matching published tag: ' . $next,
            );
        }
        $impact = $resolved->impact->value;
        $consumed = $inventory->hashes;
        $changesets = $options->resolvePullRequests
            ? $this->pullRequests->resolve($options, $baseSha, $inventory->changesets, $inventory->hashes)
            : $inventory->changesets;
        $notes = $this->notesRenderer->render($changesets, $template, $options->repository);
        $document = $this->consolidator->promote(
            $document,
            $next,
            $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d'),
            $notes,
            $template,
        );
        $document = $this->references->complete($document, $options->repository, $options->tagPrefix);

        return [$document, $next, $impact, $consumed, $notes];
    }

    /**
     * Requires an observed stable tag baseline; the empty-history 0.0.0 sentinel cannot prove a maintained release.
     *
     * @param HistoryDocument $document       Maintained history whose release bodies, introduction and references must be preserved.
     * @param string          $currentVersion Validated current stable version used as the numeric release baseline.
     * @param bool            $publishedTag   Whether the current baseline is established by an actual reachable stable Git tag.
     *
     * @return void Completion only when maintained stable sections do not exceed an actual reachable published tag baseline.
     *
     * @throws PendingPublicationException When maintained stable history lacks its reachable published tag or exceeds the observed stable baseline.
     */
    private function assertPublishedHistory(HistoryDocument $document, string $currentVersion, bool $publishedTag): void
    {
        $published = explode('.', explode('+', $currentVersion, 2)[0]);
        foreach ($document->getReleases() as $release) {
            $version = $release->getVersion();
            if (1 !== preg_match(
                '/\A[vV]?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/',
                $version,
                $matches,
            )) {
                continue;
            }
            foreach (array_slice($matches, 1, 3) as $index => $component) {
                $comparison = strlen($component) <=> strlen($published[$index]);
                if (0 === $comparison) {
                    $comparison = strcmp($component, $published[$index]);
                }
                if (! $publishedTag || 0 < $comparison) {
                    throw new PendingPublicationException(
                        'Maintained release ' . $version . ' is awaiting its reachable stable Git tag; complete its approved publication or reviewed recovery before planning another version. Latest reachable stable version: ' . $currentVersion . '.',
                    );
                }
                if (0 > $comparison) {
                    break;
                }
            }
        }
    }

    /**
     * Captures hashes of the exact bytes accepted by fragment validation, before any mutation.
     *
     * @param string $fragmentPath Absolute pending-fragment directory whose accepted inventory and hashes are captured.
     *
     * @return ValidationReport Valid fragment inventory containing one exact accepted content hash for each parsed fragment.
     *
     * @throws \InvalidArgumentException When any pending fragment fails inventory validation.
     * @throws \RuntimeException         When accepted parser values lack corresponding exact byte hashes.
     */
    private function inventory(string $fragmentPath): ValidationReport
    {
        $inventory = $this->validator->validate($fragmentPath, false, false);
        if (! $inventory->isValid()) {
            throw new InvalidArgumentException('Invalid pending fragments: ' . json_encode(
                $inventory->errors,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        }
        if (count($inventory->hashes) !== count($inventory->changesets)) {
            throw new RuntimeException('Fragment validation must include the exact accepted content hashes.');
        }

        return $inventory;
    }

    /**
     * Requires an exact before/after state, saved output, notes and settings before recovery.
     *
     * @param ReleaseOptions        $options   Validated consumer paths, presentation settings and source-selection policy.
     * @param ReleaseReceipt        $receipt   Schema-validated recovery evidence for the interrupted transaction.
     * @param string|null           $contents  Currently observed central-history bytes, or null when absent, compared with the saved before/after hashes.
     * @param ValidationReport|null $inventory Validated fragment records and accepted hashes, or null for history-only recovery without consumption.
     *
     * @return void Completion only when settings, saved output, exact notes and remaining fragment hashes still match the interrupted transaction.
     *
     * @throws \RuntimeException When settings, central bytes, saved notes or remaining accepted fragments diverge from the interrupted plan.
     */
    private function assertResumable(
        ReleaseOptions $options,
        ReleaseReceipt $receipt,
        ?string $contents,
        ?ValidationReport $inventory,
    ): void {
        $data = $receipt->data;
        if (($data['resolve_pull_requests'] ?? false) !== $options->resolvePullRequests) {
            throw new RuntimeException('Pending release PR discovery differs from the saved plan.');
        }
        foreach (['changelog_file' => $options->changelogFile, 'fragment_directory' => $options->fragmentDirectory,
            'locale' => $options->locale, 'template' => $options->template, 'tag_prefix' => $options->tagPrefix,
            'repository' => $options->repository] as $key => $value) {
            if ($data[$key] !== $value) {
                throw new RuntimeException('Pending release settings differ from the saved plan: ' . $key);
            }
        }
        $currentHash = null === $contents ? null : hash('sha256', $contents);
        $before = $currentHash === $data['before_changelog_sha256'];
        if (! $before && $currentHash !== $data['after_changelog_sha256']) {
            throw new RuntimeException('The pending release document differs from its saved plan.');
        }
        if (! hash_equals($data['notes_sha256'], hash('sha256', $data['notes']))) {
            throw new RuntimeException('The pending release notes differ from their saved plan.');
        }
        if (null === $inventory) {
            return;
        }
        if ($before && $currentHash !== $data['after_changelog_sha256'] && count($inventory->hashes) !== count(
            $data['consumed'],
        )) {
            throw new RuntimeException(
                'All approved fragments must remain available before writing the pending changelog.',
            );
        }
        foreach ($inventory->hashes as $path => $hash) {
            $relative = $this->paths->relativePath($path, $options->workingDirectory);
            if (! isset($data['consumed'][$relative]) || ! hash_equals($data['consumed'][$relative], $hash)) {
                throw new RuntimeException(
                    'Recover or publish the pending plan before collecting new or modified fragments: ' . $relative,
                );
            }
        }
    }
}
