<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

namespace FastForward\Changelog\Validator;

use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;

/** Reads only the managed input scope, preserving unrelated working-tree and index state. */
final readonly class ReleaseInputEvidenceValidator implements ReleaseInputEvidenceValidatorInterface
{
    /** Injects all Git/file/path/diagnostic boundaries without reading a repository or executing PHP. */
    public function __construct(
        private GitRepositoryInterface $git,
        private ManagedFileStoreInterface $files,
        private PackagePathResolverInterface $paths,
        private ReleaseExceptionFactoryInterface $exceptions,
    ) {}

    /** Rejects dirty, incomplete or unsafe fresh Git release inputs before journal/tree/commit writes. */
    public function validate(ReleasePlan $plan): void
    {
        if ($plan->resuming) {
            $this->validateTemplate($plan->options, $plan->baseSha);
            return;
        }
        if (null === $plan->baseSha || null === $plan->nextVersion) {
            return;
        }
        $options = $plan->options;
        $central = $this->git->readFileAt($options->workingDirectory, $plan->baseSha, $options->changelogFile);
        if ($central !== $plan->originalChangelog) {
            throw $this->exceptions->failure('Commit the central changelog before applying a release: it differs from the approved base.');
        }
        if (null !== $central) {
            $this->regularFile($options, $plan->baseSha, $options->changelogFile);
        }
        $prefix = $options->fragmentDirectory . '/';
        $selected = [];
        foreach ($this->git->filesAt($options->workingDirectory, $plan->baseSha, $options->fragmentDirectory) as $entry) {
            $path = $entry['path'];
            if (! str_ends_with($path, '.md') || $path === $prefix . 'AGENTS.md') {
                continue;
            }
            if (! str_starts_with($path, $prefix)
                || 1 !== preg_match(ChangesetParserInterface::FILENAME_PATTERN, substr($path, strlen($prefix)))
                || ! in_array($entry['mode'], ['100644', '100755'], true)) {
                throw $this->exceptions->failure('The release base contains an unsafe or noncanonical fragment: ' . $path);
            }
            $selected[] = $path;
        }
        sort($selected, SORT_STRING);
        $consumed = [];
        foreach ($plan->consumed as $path => $hash) {
            $consumed[$this->paths->relativePath($path, $options->workingDirectory)] = $hash;
        }
        ksort($consumed, SORT_STRING);
        if ($selected !== array_keys($consumed)) {
            throw $this->exceptions->failure('Commit the complete pending fragment set before applying a release: it differs from the approved base.');
        }
        foreach ($consumed as $path => $hash) {
            $contents = $this->git->readFileAt($options->workingDirectory, $plan->baseSha, $path);
            if (null === $contents || ! hash_equals($hash, hash('sha256', $contents))) {
                throw $this->exceptions->failure('Commit the accepted fragment bytes before applying a release: ' . $path);
            }
        }
        $this->validateTemplate($options, $plan->baseSha);
    }

    /** Checks executable selection before template resolution; non-Git local overrides remain supported. */
    public function validateTemplate(ReleaseOptions $options, ?string $baseSha): void
    {
        if (null === $baseSha || 'keep-a-changelog' === $options->template) {
            return;
        }
        $this->regularFile($options, $baseSha, $options->template);
        $committed = $this->git->readFileAt($options->workingDirectory, $baseSha, $options->template);
        if (null === $committed || $this->files->read($this->paths->absolutePath($options->template, $options->workingDirectory)) !== $committed) {
            throw $this->exceptions->failure('Commit the selected custom template before applying a release: it differs from the approved base.');
        }
    }

    /** Requires an exact regular blob, refusing symbolic links, directories and submodules. */
    private function regularFile(ReleaseOptions $options, string $sha, string $path): void
    {
        $entries = $this->git->filesAt($options->workingDirectory, $sha, $path);
        if (1 !== count($entries) || $entries[0]['path'] !== $path || ! in_array($entries[0]['mode'], ['100644', '100755'], true)) {
            throw $this->exceptions->failure('The release base requires an exact regular committed file: ' . $path);
        }
    }
}
