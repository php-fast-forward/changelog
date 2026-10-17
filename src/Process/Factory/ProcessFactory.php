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

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process;

/** Constructs unstarted processes with an explicit cwd and without automation credentials. */
final readonly class ProcessFactory implements ProcessFactoryInterface
{
    /**
     * Captures the consumer execution root without observing host state.
     *
     * @param string $workingDirectory Consumer execution root assigned to every constructed child process.
     */
    public function __construct(
        private string $workingDirectory,
    ) {}

    /**
     * Credentials MUST reach only the HTTP boundary, never Git, hooks or configured helper processes.
     *
     * @param  list<string>   $command Executable and structured argv entries used to construct an unstarted process.
     * @return Process        Unstarted structured process using the configured consumer root and filtered automation
     *                        credentials.
     * @throws LogicException When the PHP runtime does not provide proc_open for Symfony Process construction.
     */
    public function create(array $command): Process
    {
        return new Process($command, $this->workingDirectory, [
            'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'FF_CHANGELOG_TOKEN' => false,
            'FF_CHANGELOG_INPUTS' => false,
        ]);
    }
}
