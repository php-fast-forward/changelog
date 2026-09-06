<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Git;

use FastForward\Changelog\Filesystem\PackageFilesystemInterface;

/**
 * Resolves the origin URL for a Git working directory.
 */
final readonly class GitRepositoryUrlResolver implements GitRepositoryUrlResolverInterface
{
    /**
     * Initializes working-directory resolution and isolated process creation.
     *
     * @param PackageFilesystemInterface $filesystem resolves the working directory
     * @param ProcessFactoryInterface $processFactory creates isolated Git processes
     */
    public function __construct(
        private PackageFilesystemInterface $filesystem,
        private ProcessFactoryInterface $processFactory,
    ) {}

    /**
     * Returns the configured origin URL or null when it cannot be resolved.
     *
     * The resolver MUST NOT throw for an absent directory, a failed Git command,
     * or empty command output.
     */
    public function resolve(?string $workingDirectory): ?string
    {
        if (null === $workingDirectory || '' === trim($workingDirectory)) {
            return null;
        }

        $process = $this->processFactory->create(['git', 'config', '--get', 'remote.origin.url']);
        $process->setWorkingDirectory($this->filesystem->getAbsolutePath($workingDirectory));
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $repositoryUrl = trim($process->getOutput());

        return '' === $repositoryUrl ? null : $repositoryUrl;
    }
}
