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

namespace FastForward\Changelog\Console\Command;

use FastForward\Changelog\Console\Input\MutationInput;
use FastForward\Changelog\Console\PlanCommandRunnerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Output\OutputInterface;

/** Collect pending fragments into one version transaction. */
#[AsCommand(
    name: 'version',
    description: 'Collect pending fragments into one version transaction.',
)]
final readonly class VersionCommand
{
    /** Captures the transaction runner without planning or applying a release. */
    public function __construct(
        private PlanCommandRunnerInterface $runner,
    ) {}

    /** Delegates the selected operation through the shared transaction boundary. */
    public function __invoke(#[MapInput] MutationInput $input, OutputInterface $output): int
    {
        return $this->runner->run($input, 'version', $output);
    }
}
