<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Git;

use FastForward\Changelog\Filesystem\PackageFilesystem;
use Symfony\Component\Process\Process;

final readonly class GitRepositoryUrlResolver
{
    public function __construct(
        private PackageFilesystem $filesystem,
    ) {}

    public function resolve(?string $workingDirectory): ?string
    {
        if (null === $workingDirectory || '' === trim($workingDirectory)) {
            return null;
        }

        $process = new Process(['git', 'config', '--get', 'remote.origin.url']);
        $process->setWorkingDirectory($this->filesystem->getAbsolutePath($workingDirectory));
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $repositoryUrl = trim($process->getOutput());

        return '' === $repositoryUrl ? null : $repositoryUrl;
    }
}
