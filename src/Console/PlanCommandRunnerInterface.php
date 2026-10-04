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

namespace FastForward\Changelog\Console;

use FastForward\Changelog\Console\Input\MutationInput;
use Symfony\Component\Console\Output\OutputInterface;

/** Runs the shared transaction boundary for version and maintenance entrypoints. */
interface PlanCommandRunnerInterface
{
    /** Plans once and applies only when neither read-only mode was selected. */
    public function run(MutationInput $input, string $operation, OutputInterface $output): int;
}
