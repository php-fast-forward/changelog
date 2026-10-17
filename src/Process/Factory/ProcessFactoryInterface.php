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

/** Replaces process construction so unit tests cannot start Git or inherit host credentials. */
interface ProcessFactoryInterface
{
    /**
     * Returns an unstarted process for one argv sequence and the explicit consumer root.
     *
     * @param  list<string>   $command Executable and structured argv entries used to construct an unstarted process.
     * @return Process        Unstarted structured process using the configured consumer root and filtered automation
     *                        credentials.
     * @throws LogicException When the PHP runtime does not provide proc_open for Symfony Process construction.
     */
    public function create(array $command): Process;
}
