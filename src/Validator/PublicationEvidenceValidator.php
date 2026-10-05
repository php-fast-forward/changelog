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

use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Publication\Factory\PublicationEvidenceFactoryInterface;
use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseHistoryConsolidatorInterface;
use FastForward\Changelog\Release\ReleaseNotesRendererInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Version\NextVersionResolverInterface;

/** Recomputes release semantics from committed fragment bytes without relying on a generated repository file. */
final readonly class PublicationEvidenceValidator implements PublicationEvidenceValidatorInterface
{
    /** Injects read-only Git/domain proof and guarded custom-template boundaries without I/O. */
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
        private PublicationEvidenceFactoryInterface $evidence,
        private ReleaseExceptionFactoryInterface $exceptions,
        private ReleaseHistoryConsolidatorInterface $consolidator,
    ) {}

    /** Validates exact blobs, complete base inventory, ancestry, recomputed impact/version and central note bytes. */
    public function validate(ReleaseOptions $options, string $approvedSha): PublicationEvidence
    {
        if (1 !== preg_match('~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D', $approvedSha)
            || $this->git->resolveRef($options->workingDirectory, $approvedSha) !== $approvedSha) {
            throw $this->exceptions->invalid('Publication requires the complete exact approved commit SHA.');
        }
        $central = $this->git->readFileAt($options->workingDirectory, $approvedSha, $options->changelogFile);
        if (null !== $central) {
            $this->regularFile($options, $approvedSha, $options->changelogFile);
        }
        $data = $this->git->releaseMetadata($options->workingDirectory, $approvedSha, $options->changelogFile);
        if (null === $data) {
            throw $this->exceptions->invalid('The approved commit lacks generated release evidence. Preserve the four Changelog trailers when committing or squashing a consolidation.');
        }
        if (null === $central || hash('sha256', $central) !== $data['output_sha256']
            || $options->evidenceHash() !== $data['options_sha256']) {
            throw $this->exceptions->invalid('The approved central history or settings differ from the generated commit evidence.');
        }
        $base = $data['base_sha'];
        if (null === $options->repository || ! $this->git->isAncestor($options->workingDirectory, $base, $approvedSha)) {
            throw $this->exceptions->invalid('Publication requires a selected GitHub repository and the generated source-base ancestry.');
        }
        $baseCentral = $this->git->readFileAt($options->workingDirectory, $base, $options->changelogFile);
        if (null !== $baseCentral) {
            $this->regularFile($options, $base, $options->changelogFile);
        }
        $selected = $this->pendingFragments($options, $base, true);
        $remaining = $this->pendingFragments($options, $approvedSha, false);
        if ([] !== $remaining) {
            if ($selected !== $remaining) {
                throw $this->exceptions->invalid('The approved consolidation still contains pending Markdown fragments from an incomplete or unexpected selection.');
            }
            foreach ($remaining as $path) {
                $before = $this->git->readFileAt($options->workingDirectory, $base, $path);
                if (null === $before || $before !== $this->git->readFileAt($options->workingDirectory, $approvedSha, $path)) {
                    throw $this->exceptions->invalid('Maintenance requires every pending fragment to retain its exact source-base bytes: ' . $path);
                }
            }
        }
        $reachable = [];
        foreach ($this->git->tags($options->workingDirectory) as $tag) {
            if ($this->git->isAncestor($options->workingDirectory, $tag['sha'], $base)) {
                $reachable[] = $tag;
            }
        }
        $current = $this->importer->currentVersion($reachable, $options->tagPrefix);
        $this->trustedTemplate($options, $approvedSha);
        $template = $this->templates->resolve($options);
        $approved = $this->history->parse($central, $template);
        $source = $this->history->parse($baseCentral ?? '', $template);
        $document = $this->backfilledHistory($source, $approved, $options, $template, $reachable);
        if ([] !== $remaining || [] === $selected) {
            if ((null !== $document && $central === $this->history->render($document, $template, true))
                || $central === $this->history->render($source, $template, false)) {
                return $this->evidence->create($approvedSha, null, null, '', $options->repository);
            }
            throw $this->exceptions->invalid('The approved history is not supported backfill or format maintenance; an incomplete release must consume every source fragment.');
        }
        if (null === $document) {
            throw $this->exceptions->invalid('The approved release is missing a historical section for a reachable stable Git tag.');
        }
        $this->assertPublishedHistory($source, $current, $options->tagPrefix, $base, in_array($options->tagPrefix . $current, array_column($reachable, 'name'), true));
        $changesets = [];
        foreach ($selected as $path) {
            $contents = $this->git->readFileAt($options->workingDirectory, $base, $path);
            if (null === $contents) {
                throw $this->exceptions->invalid('A consumed fragment is missing from its source base: ' . $path);
            }
            $result = $this->parser->parse($this->paths->absolutePath($path, $options->workingDirectory), $contents);
            if (! $result->isValid()) {
                throw $this->exceptions->invalid('A consumed base fragment is invalid: ' . $path . ' ' . implode('; ', $result->errors));
            }
            if (null !== $this->git->readFileAt($options->workingDirectory, $approvedSha, $path)) {
                throw $this->exceptions->invalid('A consumed fragment still exists at the approved commit: ' . $path);
            }
            $changesets[] = $result->changeset;
        }
        $resolved = $this->versions->resolve($current, $changesets);
        if (! $resolved->isValid()) {
            throw $this->exceptions->invalid('The committed fragment set does not resolve a valid release version.');
        }
        $version = $resolved->nextVersion;
        $section = $approved->getRelease($version);
        if (null === $section) {
            throw $this->exceptions->invalid('The approved history has no section for the computed release version.');
        }
        $rendered = $this->notes->render($changesets, $template, $options->repository);
        if (null !== $document->getRelease($version)) {
            throw $this->exceptions->invalid('The computed release already exists in the source-base history.');
        }
        $expected = $this->history->render($this->consolidator->promote($document, $version, $section->getDate(), $rendered, $template), $template, true);
        if ($central !== $expected) {
            throw $this->exceptions->invalid('The approved history is not the canonical consolidation of every source-base fragment and exact prior history.');
        }
        $centralNotes = $this->history->notes($approved, $version);
        return $this->evidence->create($approvedSha, $version, $options->tagPrefix . $version, $centralNotes, $options->repository);
    }

    /** Requires an observed stable tag before treating any maintained stable core, including 0.0.0, as published. */
    private function assertPublishedHistory(HistoryDocument $source, string $current, string $prefix, string $base, bool $publishedTag): void
    {
        $published = explode('+', $current, 2)[0];
        foreach ($source->getReleases() as $release) {
            if (1 !== preg_match('/\A[vV]?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/', $release->getVersion(), $matches)) {
                continue;
            }
            if (! $publishedTag || $this->higher(implode('.', array_slice($matches, 1, 3)), $published, $prefix, $base)) {
                throw $this->exceptions->invalid('The source-base history already contains a maintained release awaiting its reachable stable Git tag: ' . $release->getVersion());
            }
        }
    }

    /** Reuses approved imported snapshots while every pre-existing section retains its exact source-base presentation. */
    private function backfilledHistory(HistoryDocument $source, HistoryDocument $approved, ReleaseOptions $options, TemplateInterface $template, array $tags): ?HistoryDocument
    {
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
            $section = $this->releases->create($version, $snapshot->getDate(), $snapshot->getDateSource(), $snapshot->getBody());
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

    /** Accepts exactly the stable identities that the shared historical importer accepts. */
    private function stable(string $version): bool
    {
        return 1 === preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/', $version);
    }

    /** Delegates ordering to the pure shared tag baseline, retaining large numeric components and build tie semantics. */
    private function higher(string $left, string $right, string $prefix, string $sha): bool
    {
        return $left !== $right && $left === $this->importer->currentVersion([
            ['name' => $prefix . $left, 'sha' => $sha, 'date' => null, 'date_source' => null],
            ['name' => $prefix . $right, 'sha' => $sha, 'date' => null, 'date_source' => null],
        ], $prefix);
    }

    /** Inventories the complete canonical pending scope while retaining safe maintenance without consumption. */
    private function pendingFragments(ReleaseOptions $options, string $sha, bool $source): array
    {
        $selected = [];
        foreach ($this->git->filesAt($options->workingDirectory, $sha, $options->fragmentDirectory) as $entry) {
            $path = $entry['path'];
            if (! str_ends_with($path, '.md') || $path === $options->fragmentDirectory . '/AGENTS.md') {
                continue;
            }
            $prefix = $options->fragmentDirectory . '/';
            if (! str_starts_with($path, $prefix)
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($prefix)))
                || ! in_array($entry['mode'], ['100644', '100755'], true)) {
                throw $this->exceptions->invalid(($source
                    ? 'The source base contains an unsafe or noncanonical fragment: '
                    : 'The approved consolidation still contains pending Markdown fragments that are unsafe or noncanonical: ') . $path);
            }
            $selected[] = $path;
        }
        sort($selected, SORT_STRING);
        return $selected;
    }

    /** Requires an exact regular blob, refusing Git symlink/submodule entries before any execution. */
    private function regularFile(ReleaseOptions $options, string $sha, string $path): void
    {
        $entries = $this->git->filesAt($options->workingDirectory, $sha, $path);
        if (1 !== count($entries) || $entries[0]['path'] !== $path || ! in_array($entries[0]['mode'], ['100644', '100755'], true)) {
            throw $this->exceptions->invalid('Publication requires an exact regular committed file: ' . $path);
        }
    }

    /** Pins explicit executable template selection to an unchanged tracked blob in the approved checkout. */
    private function trustedTemplate(ReleaseOptions $options, string $sha): void
    {
        if ('keep-a-changelog' === $options->template) {
            return;
        }
        $path = $options->template;
        if ('' === $path || $this->paths->isAbsolute($path) || str_contains($path, '\\') || str_contains($path, "\0")
            || [] !== array_intersect(explode('/', $path), ['', '.', '..'])
            || $this->git->resolveRef($options->workingDirectory, 'HEAD') !== $sha) {
            throw $this->exceptions->invalid('Publication custom templates require a canonical tracked path in the approved checkout.');
        }
        $this->regularFile($options, $sha, $path);
        $committed = $this->git->readFileAt($options->workingDirectory, $sha, $path);
        if (null === $committed || $this->files->read($this->paths->absolutePath($path, $options->workingDirectory)) !== $committed) {
            throw $this->exceptions->invalid('The selected custom template differs from the exact approved committed blob.');
        }
    }
}
