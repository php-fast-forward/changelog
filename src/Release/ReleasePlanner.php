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
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\Factory\ReleasePlanFactoryInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidatorInterface;
use FastForward\Changelog\Version\NextVersionResolverInterface;
use Psr\Clock\ClockInterface;

/** Collects exact validated inputs before selecting one deterministic release transaction. */
final readonly class ReleasePlanner implements ReleasePlannerInterface
{
    /** Composes domain rules and replaceable external boundaries; construction performs no I/O. */
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
        private HistoryReleaseFactoryInterface $releases,
        private ClockInterface $clock,
        private DateTimeZone $timezone,
        private ReceiptCodecInterface $receipts,
        private ReleasePlanFactoryInterface $plans,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /**
     * Reuses the saved transaction after partial application instead of incrementing it again.
     * Backfill and format MUST NOT consume pending fragments or create a new version.
     */
    public function plan(ReleaseOptions $options, string $operation = 'version'): ReleasePlan
    {
        if (! in_array($operation, ['version', 'backfill', 'format'], true)) {
            throw $this->exceptions->invalid('Operation must be version, backfill or format.');
        }
        $changelogPath = $this->paths->absolutePath($options->changelogFile, $options->workingDirectory);
        $fragmentPath = $this->paths->absolutePath($options->fragmentDirectory, $options->workingDirectory);
        $receiptPath = $this->paths->absolutePath($options->fragmentDirectory . '/release-plan.json', $options->workingDirectory);
        $original = $this->files->read($changelogPath);
        $receiptBytes = $this->files->read($receiptPath);
        $document = $this->history->parse($original ?? '');
        $template = $this->templates->resolve($options);
        $baseSha = null;
        $tags = [];
        if ($this->git->isRepository($options->workingDirectory)) {
            $baseSha = $this->git->resolveRef($options->workingDirectory, $options->baseRef);
            if ($baseSha !== $this->git->resolveRef($options->workingDirectory)) {
                throw $this->exceptions->failure('Check out the selected base revision before planning a release.');
            }
            $tags = array_values(array_filter(
                $this->git->tags($options->workingDirectory),
                fn(array $tag): bool => $this->git->isAncestor($options->workingDirectory, $tag['sha'], $baseSha),
            ));
        }
        if (null !== $receiptBytes) {
            $receipt = $this->receipts->decode($receiptBytes);
            $version = $receipt->data['next_version'];
            $published = null !== $version && array_any($tags, static fn(array $tag): bool => $tag['name'] === $options->tagPrefix . $version);
            $currentHash = null === $original ? null : hash('sha256', $original);
            $prepared = $receipt->data['before_changelog_sha256'] === $currentHash
                && $receipt->data['after_changelog_sha256'] !== $currentHash;
            if ($prepared || (null !== $version && ! $published)) {
                if (null !== $version && 'version' !== $operation) {
                    throw $this->exceptions->failure('Recover or publish the pending release plan before maintaining its history.');
                }
                $inventory = null === $version ? null : $this->inventory($fragmentPath);
                $this->assertResumable($options, $receipt, $original, $inventory);
                return $this->plans->resume($options, $receipt, $changelogPath, $original, $receiptPath, $receiptBytes);
            }
        }
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
            $resolved = $this->versions->resolve($currentVersion, $inventory->changesets);
            if (! $resolved->isValid()) {
                throw $this->exceptions->invalid('Cannot calculate the next version: ' . implode('; ', $resolved->errors));
            }
            $next = $resolved->nextVersion;
            if (null !== $document->getRelease($next)) {
                throw $this->exceptions->failure('The next version already has a local section without a matching published tag: ' . $next);
            }
            $impact = $resolved->impact->value;
            $consumed = $inventory->hashes;
            $notes = $this->notesRenderer->render($inventory->changesets, $template, $options->repository);
            $release = $this->releases->create($next, $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d'), 'release-plan', $notes);
            $existing = $document->getReleases();
            $position = isset($existing[0]) && 'unreleased' === $existing[0]->getVersion() ? 1 : 0;
            array_splice($existing, $position, 0, [$release]);
            $document = $document->withReleases($existing);
        }
        $changed = 'format' === $operation || null !== $next || [] !== $missing;
        $contents = $changed ? $this->history->render($document, $template, 'format' !== $operation) : ($original ?? '');
        if (null !== $next) {
            $notes = $this->history->notes($this->history->parse($contents), $next);
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

    /** Captures hashes of the exact bytes accepted by fragment validation, before any mutation. */
    private function inventory(string $fragmentPath): ValidationReport
    {
        $inventory = $this->validator->validate($fragmentPath, false, false);
        if (! $inventory->isValid()) {
            throw $this->exceptions->invalid('Invalid pending fragments: ' . json_encode($inventory->errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        if (count($inventory->hashes) !== count($inventory->changesets)) {
            throw $this->exceptions->failure('Fragment validation must include the exact accepted content hashes.');
        }
        return $inventory;
    }

    /** Requires an exact before/after state, saved output, notes and settings before recovery. */
    private function assertResumable(ReleaseOptions $options, ReleaseReceipt $receipt, ?string $contents, ?ValidationReport $inventory): void
    {
        $data = $receipt->data;
        foreach (['changelog_file' => $options->changelogFile, 'fragment_directory' => $options->fragmentDirectory,
            'locale' => $options->locale, 'template' => $options->template, 'tag_prefix' => $options->tagPrefix,
            'repository' => $options->repository] as $key => $value) {
            if ($data[$key] !== $value) {
                throw $this->exceptions->failure('Pending release settings differ from the saved plan: ' . $key);
            }
        }
        $currentHash = null === $contents ? null : hash('sha256', $contents);
        $before = $currentHash === $data['before_changelog_sha256'];
        if (! $before && $currentHash !== $data['after_changelog_sha256']) {
            throw $this->exceptions->failure('The pending release document differs from its saved plan.');
        }
        $notes = null === $data['next_version'] ? '' : $this->history->notes($this->history->parse($data['changelog_contents']), $data['next_version']);
        if (! hash_equals($data['notes_sha256'], hash('sha256', $notes))) {
            throw $this->exceptions->failure('The pending release notes differ from their saved plan.');
        }
        if (null === $inventory) {
            return;
        }
        if ($before && $currentHash !== $data['after_changelog_sha256'] && count($inventory->hashes) !== count($data['consumed'])) {
            throw $this->exceptions->failure('All approved fragments must remain available before writing the pending changelog.');
        }
        foreach ($inventory->hashes as $path => $hash) {
            $relative = $this->paths->relativePath($path, $options->workingDirectory);
            if (! isset($data['consumed'][$relative]) || ! hash_equals($data['consumed'][$relative], $hash)) {
                throw $this->exceptions->failure('Recover or publish the pending plan before collecting new or modified fragments: ' . $relative);
            }
        }
    }
}
