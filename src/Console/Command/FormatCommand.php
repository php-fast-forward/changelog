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

/** Format the central changelog without consuming pending fragments. */
#[AsCommand(
    name: 'format',
    description: 'Format the central changelog without consuming pending fragments.',
)]
final readonly class FormatCommand
{
    /**
     * Captures the transaction runner without planning or applying a release.
     *
     * @param PlanCommandRunnerInterface $runner Coordinates the shared operation while keeping command-specific adapters thin.
     */
    public function __construct(
        private PlanCommandRunnerInterface $runner,
    ) {}

    /**
     * Delegates the selected operation through the shared transaction boundary.
     *
     * @param MutationInput   $input  Shared release settings and mutually exclusive dry-run/check mode flags.
     * @param OutputInterface $output Output stream receiving machine results or controlled command diagnostics.
     *
     * @return int Symfony exit status reflecting the command's result or controlled input/operation failure.
     */
    public function __invoke(#[MapInput] MutationInput $input, OutputInterface $output): int
    {
        return $this->runner->run($input, 'format', $output);
    }
}
