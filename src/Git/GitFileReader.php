<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://github.com/php-fast-forward/changelog/issues
 * @see       https://php-fast-forward.github.io/changelog/
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Git;

use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use RuntimeException;

/**
 * Reads a file from an immutable Git reference through an injected process factory.
 */
final readonly class GitFileReader implements GitFileReaderInterface
{
    /**
     * Initializes injectable process creation and path normalization boundaries.
     *
     * @param ProcessFactoryInterface $processFactory creates the Git process
     * @param PackagePathResolverInterface $pathResolver normalizes repository-relative paths
     */
    public function __construct(
        private ProcessFactoryInterface $processFactory,
        private PackagePathResolverInterface $pathResolver,
    ) {}

    /**
     * Reads file contents without falling back to a working-tree copy.
     *
     * Missing files MUST be distinguished from an invalid reference or Git
     * execution failure so callers cannot approve an unknown baseline.
     */
    public function show(string $reference, string $path, ?string $workingDirectory = null): string
    {
        if (null !== $workingDirectory && $this->pathResolver->isAbsolute($path)) {
            $path = $this->pathResolver->relativePath($path, $workingDirectory);
        }

        $process = $this->processFactory->create(['git', 'show', $reference . ':' . $path]);

        if (null !== $workingDirectory) {
            $process->setWorkingDirectory($workingDirectory);
        }

        $process->run();

        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }

        $errorOutput = trim($process->getErrorOutput());

        if (str_contains($errorOutput, 'exists on disk, but not in')
            || str_contains($errorOutput, 'does not exist in')
        ) {
            throw new GitFileNotFoundException($errorOutput);
        }

        throw new RuntimeException('' === $errorOutput ? 'Git could not read the requested changelog baseline.' : $errorOutput);
    }
}
