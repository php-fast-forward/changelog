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

use FastForward\Changelog\Git\GitRepositoryInterface;

/** Selects private project-scoped journal storage through injected Git discovery and temporary paths. */
final readonly class ReleaseJournalPathResolver implements ReleaseJournalPathResolverInterface
{
    /**
     * Injects Git discovery and caller-owned temporary storage; construction performs no I/O.
     *
     * @param GitRepositoryInterface $git                Observes repository identities, committed bytes and scoped operations through an injected boundary.
     * @param string                 $temporaryDirectory Injected canonical temporary root for private recovery journals outside Git.
     */
    public function __construct(
        private GitRepositoryInterface $git,
        private string $temporaryDirectory,
    ) {}

    /**
     * Scopes recovery by consumer root, fragment directory and central-history path outside the versioned tree.
     *
     * @param ReleaseOptions $options Validated consumer paths, presentation settings and source-selection policy.
     *
     * @return string Private recovery-journal path in the Git administrative directory or injected temporary storage.
     */
    public function resolve(ReleaseOptions $options): string
    {
        $scope = hash('sha256', json_encode([
            rtrim(str_replace('\\', '/', $options->workingDirectory), '/'),
            $options->fragmentDirectory, $options->changelogFile,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $gitPath = $this->git->journalPath($options->workingDirectory);

        return null !== $gitPath
            ? substr($gitPath, 0, -strlen('.json')) . '-' . $scope . '.json'
            : rtrim(
                str_replace('\\', '/', $this->temporaryDirectory),
                '/',
            ) . '/fast-forward-changelog/' . $scope . '/release-plan.json';
    }
}
