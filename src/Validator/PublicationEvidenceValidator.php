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
use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Publication\Factory\PublicationEvidenceFactoryInterface;
use FastForward\Changelog\Publication\PublicationEvidence;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseNotesRendererInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\TemplateResolverInterface;
use FastForward\Changelog\Version\NextVersionResolverInterface;

/** Recomputes release semantics from committed fragment bytes rather than trusting receipt integrity as authority. */
final readonly class PublicationEvidenceValidator implements PublicationEvidenceValidatorInterface
{
    /** Injects read-only Git/domain proof and guarded custom-template boundaries without I/O. */
    public function __construct(
        private GitRepositoryInterface $git,
        private ReceiptCodecInterface $receipts,
        private HistoryCodecInterface $history,
        private ChangesetParserInterface $parser,
        private NextVersionResolverInterface $versions,
        private HistoryImporterInterface $importer,
        private ReleaseNotesRendererInterface $notes,
        private TemplateResolverInterface $templates,
        private PackagePathResolverInterface $paths,
        private ManagedFileStoreInterface $files,
        private HistoryReleaseFactoryInterface $releases,
        private HistoryDocumentFactoryInterface $documents,
        private PublicationEvidenceFactoryInterface $evidence,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /** Validates exact blobs, complete base inventory, ancestry, recomputed impact/version and central note bytes. */
    public function validate(ReleaseOptions $options, string $approvedSha): PublicationEvidence
    {
        if (1 !== preg_match('~^(?:[a-f0-9]{40}|[a-f0-9]{64})$~D', $approvedSha)
            || $this->git->resolveRef($options->workingDirectory, $approvedSha) !== $approvedSha) {
            throw $this->exceptions->invalid('Publication requires the complete exact approved commit SHA.');
        }
        $receiptPath = $options->fragmentDirectory . '/release-plan.json';
        $this->regularFile($options, $approvedSha, $receiptPath);
        $raw = $this->git->readFileAt($options->workingDirectory, $approvedSha, $receiptPath);
        if (null === $raw) {
            throw $this->exceptions->invalid('The approved commit has no release receipt.');
        }
        $data = $this->receipts->decode($raw)->data;
        foreach (['fragment_directory' => $options->fragmentDirectory, 'changelog_file' => $options->changelogFile,
            'locale' => $options->locale, 'template' => $options->template, 'tag_prefix' => $options->tagPrefix,
            'repository' => $options->repository] as $field => $value) {
            if ($data[$field] !== $value) {
                throw $this->exceptions->invalid('Publication setting differs from the approved receipt: ' . $field);
            }
        }
        $this->regularFile($options, $approvedSha, $options->changelogFile);
        $central = $this->git->readFileAt($options->workingDirectory, $approvedSha, $options->changelogFile);
        if (null === $central || $central !== $data['changelog_contents']
            || ! hash_equals($data['after_changelog_sha256'], hash('sha256', $central))) {
            throw $this->exceptions->invalid('The approved central changelog differs from its receipt snapshot.');
        }
        if (null === $data['next_version']) {
            return $this->evidence->create($approvedSha, null, null, '', $options->repository);
        }
        if (null === $options->repository || null === $data['base_sha']
            || ! $this->git->isAncestor($options->workingDirectory, $data['base_sha'], $approvedSha)) {
            throw $this->exceptions->invalid('Publication requires a selected GitHub repository and the approved receipt base ancestry.');
        }
        $baseCentral = $this->git->readFileAt($options->workingDirectory, $data['base_sha'], $options->changelogFile);
        if ($data['before_changelog_sha256'] !== (null === $baseCentral ? null : hash('sha256', $baseCentral))) {
            throw $this->exceptions->invalid('The receipt original changelog hash does not match its base commit.');
        }
        if (null !== $baseCentral) {
            $this->regularFile($options, $data['base_sha'], $options->changelogFile);
        }
        $selected = [];
        foreach ($this->git->filesAt($options->workingDirectory, $data['base_sha'], $options->fragmentDirectory) as $entry) {
            $path = $entry['path'];
            if (! str_ends_with($path, '.md') || $path === $options->fragmentDirectory . '/AGENTS.md') {
                continue;
            }
            $prefix = $options->fragmentDirectory . '/';
            if (! str_starts_with($path, $prefix)
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($prefix)))
                || ! in_array($entry['mode'], ['100644', '100755'], true)) {
                throw $this->exceptions->invalid('The receipt base contains an unsafe or noncanonical fragment: ' . $path);
            }
            $selected[] = $path;
        }
        sort($selected, SORT_STRING);
        $consumed = array_keys($data['consumed']);
        sort($consumed, SORT_STRING);
        if ($selected !== $consumed) {
            throw $this->exceptions->invalid('The receipt must consume the complete pending fragment set at its base commit.');
        }
        foreach ($this->git->filesAt($options->workingDirectory, $approvedSha, $options->fragmentDirectory) as $entry) {
            if (str_ends_with($entry['path'], '.md') && $entry['path'] !== $options->fragmentDirectory . '/AGENTS.md') {
                throw $this->exceptions->invalid('The approved consolidation still contains pending Markdown fragments: ' . $entry['path']);
            }
        }
        $changesets = [];
        foreach ($consumed as $path) {
            $contents = $this->git->readFileAt($options->workingDirectory, $data['base_sha'], $path);
            if (null === $contents || ! hash_equals($data['consumed'][$path], hash('sha256', $contents))) {
                throw $this->exceptions->invalid('A consumed fragment does not match its committed base hash: ' . $path);
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
        $reachable = [];
        foreach ($this->git->tags($options->workingDirectory) as $tag) {
            if ($this->git->isAncestor($options->workingDirectory, $tag['sha'], $data['base_sha'])) {
                $reachable[] = $tag;
            }
        }
        $current = $this->importer->currentVersion($reachable, $options->tagPrefix);
        $resolved = $this->versions->resolve($current, $changesets);
        if ($current !== $data['current_version'] || ! $resolved->isValid()
            || $resolved->nextVersion !== $data['next_version'] || $resolved->impact->value !== $data['impact']) {
            throw $this->exceptions->invalid('The receipt release version or impact does not match its committed fragment evidence.');
        }
        $version = $data['next_version'];
        $centralNotes = $this->history->notes($this->history->parse($central), $version);
        if ($centralNotes !== $data['notes'] || ! hash_equals($data['notes_sha256'], hash('sha256', $centralNotes))) {
            throw $this->exceptions->invalid('The approved central section notes differ from the receipt evidence.');
        }
        $this->trustedTemplate($options, $approvedSha);
        $template = $this->templates->resolve($options);
        $rendered = $this->notes->render($changesets, $template, $options->repository);
        $isolated = $this->documents->create([$this->releases->create($version, null, 'release-plan', $rendered)]);
        $expected = $this->history->notes($this->history->parse($this->history->render($isolated, $template)), $version);
        if ($centralNotes !== $expected) {
            throw $this->exceptions->invalid('The approved section is not the canonical notes generated by every consumed base fragment.');
        }
        return $this->evidence->create($approvedSha, $version, $options->tagPrefix . $version, $centralNotes, $options->repository);
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
