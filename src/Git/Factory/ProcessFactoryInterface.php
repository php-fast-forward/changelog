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

namespace FastForward\Changelog\Git\Factory;

use Symfony\Component\Process\Process;

/** Replaces process construction so unit tests cannot start Git or inherit host credentials. */
interface ProcessFactoryInterface
{
    /** Returns an unstarted process for one argv sequence and the explicit consumer root. */
    public function create(array $command): Process;
}
