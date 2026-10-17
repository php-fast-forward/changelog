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
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use RuntimeException;

/** Reads only the managed input scope, preserving unrelated working-tree and index state. */
final readonly class ReleaseInputEvidenceValidator implements ReleaseInputEvidenceValidatorInterface
{
    /**
     * Injects all Git/file/path/diagnostic boundaries without reading a repository or executing PHP.
     *
     * @param GitRepositoryInterface       $git   Replaceable repository boundary for immutable blobs, identities, ancestry and
     *                                            local Git operations.
     * @param ManagedFileStoreInterface    $files Replaceable managed-file reader used to compare checkout bytes with
     *                                            committed evidence.
     * @param PackagePathResolverInterface $paths Consumer-root path resolver used to constrain managed file access.
     */
    public function __construct(
        private GitRepositoryInterface $git,
        private ManagedFileStoreInterface $files,
        private PackagePathResolverInterface $paths,
    ) {}

    /**
     * Rejects dirty, incomplete or unsafe fresh Git release inputs before journal/tree/commit writes.
     *
     * @param  ReleasePlan      $plan Immutable release transaction whose input and output evidence must remain consistent.
     * @return void             No value; applicable managed inputs must match the approved base before release mutation.
     * @throws RuntimeException When managed input bytes, regular-file scope or executable-template evidence cannot
     *                          match the approved base.
     */
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
        $central = $this->git->readFileAt(
            $options->workingDirectory,
            $plan->baseSha,
            $options->changelogFile,
        );

        if ($central !== $plan->originalChangelog) {
            throw new RuntimeException(
                'Commit the central changelog before applying a release: it differs from the approved base.',
            );
        }

        if (null !== $central) {
            $this->regularFile($options, $plan->baseSha, $options->changelogFile);
        }
        $prefix = $options->fragmentDirectory . '/';
        $selected = [];

        foreach ($this->git->filesAt(
            $options->workingDirectory,
            $plan->baseSha,
            $options->fragmentDirectory,
        ) as $entry) {
            $path = $entry['path'];

            if (! str_ends_with($path, '.md') || $path === $prefix . 'AGENTS.md') {
                continue;
            }

            if (! str_starts_with($path, $prefix)
                || 1 !== preg_match(
                    ChangesetParserInterface::FILENAME_PATTERN,
                    substr($path, strlen($prefix)),
                )
                || ! in_array($entry['mode'], ['100644', '100755'], true)) {
                throw new RuntimeException('The release base contains an unsafe or noncanonical fragment: ' . $path);
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
            throw new RuntimeException(
                'Commit the complete pending fragment set before applying a release: it differs from the approved base.',
            );
        }

        foreach ($consumed as $path => $hash) {
            $contents = $this->git->readFileAt($options->workingDirectory, $plan->baseSha, $path);

            if (null === $contents || ! hash_equals($hash, hash('sha256', $contents))) {
                throw new RuntimeException('Commit the accepted fragment bytes before applying a release: ' . $path);
            }
        }
        $this->validateTemplate($options, $plan->baseSha);
    }

    /**
     * Checks executable selection before template resolution; non-Git local overrides remain supported.
     *
     * @param  ReleaseOptions   $options Trusted consumer paths, rendering settings and repository selection for this
     *                                   operation.
     * @param  ?string          $baseSha Approved Git base identity, or null for an explicitly non-Git operation.
     * @return void             No value; applicable executable selection must match a regular source-base blob.
     * @throws RuntimeException When managed input bytes, regular-file scope or executable-template evidence cannot
     *                          match the approved base.
     */
    public function validateTemplate(ReleaseOptions $options, ?string $baseSha): void
    {
        if (null === $baseSha || 'keep-a-changelog' === $options->template) {
            return;
        }
        $this->regularFile($options, $baseSha, $options->template);
        $committed = $this->git->readFileAt($options->workingDirectory, $baseSha, $options->template);

        if (null === $committed || $this->files->read(
            $this->paths->absolutePath($options->template, $options->workingDirectory),
        ) !== $committed) {
            throw new RuntimeException(
                'Commit the selected custom template before applying a release: it differs from the approved base.',
            );
        }
    }

    /**
     * Requires an exact regular blob, refusing symbolic links, directories and submodules.
     *
     * @param  ReleaseOptions   $options Trusted consumer paths, rendering settings and repository selection for this
     *                                   operation.
     * @param  string           $sha     Complete immutable Git object identity selected for this evidence or result.
     * @param  string           $path    Canonical repository-relative managed path selected for this operation.
     * @return void             No value; links, submodules, directories and ambiguous committed entries are refused.
     * @throws RuntimeException When managed input bytes, regular-file scope or executable-template evidence cannot
     *                          match the approved base.
     */
    private function regularFile(ReleaseOptions $options, string $sha, string $path): void
    {
        $entries = $this->git->filesAt($options->workingDirectory, $sha, $path);

        if (1 !== count($entries) || $entries[0]['path'] !== $path || ! in_array(
            $entries[0]['mode'],
            ['100644', '100755'],
            true,
        )) {
            throw new RuntimeException('The release base requires an exact regular committed file: ' . $path);
        }
    }
}
