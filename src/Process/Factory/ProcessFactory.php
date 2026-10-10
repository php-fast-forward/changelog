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

namespace FastForward\Changelog\Process\Factory;

use Symfony\Component\Process\Process;

/** Constructs unstarted processes with an explicit cwd and without automation credentials. */
final readonly class ProcessFactory implements ProcessFactoryInterface
{
    /** Captures the consumer execution root without observing host state. */
    public function __construct(
        private string $workingDirectory,
    ) {}

    /**
     * Credentials MUST reach only the HTTP boundary, never Git, hooks or configured helper processes.
     * @param list<string> $command structured executable arguments
     */
    public function create(array $command): Process
    {
        return new Process($command, $this->workingDirectory, [
            'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false,
            'FF_CHANGELOG_INPUTS' => false,
        ]);
    }
}
