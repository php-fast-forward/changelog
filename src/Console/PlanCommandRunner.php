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
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleaseApplierInterface;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Uses one planner and one applier for release and maintenance commands. */
final readonly class PlanCommandRunner implements PlanCommandRunnerInterface
{
    /** Captures replaceable business collaborators without constructing an I/O transaction. */
    public function __construct(
        private ReleaseOptionsFactoryInterface $options,
        private ReleasePlannerInterface $planner,
        private ReleaseApplierInterface $applier,
    ) {}

    /** Rejects contradictory modes before planning and keeps dry-run/check entirely read-only. */
    public function run(MutationInput $input, string $operation, OutputInterface $output): int
    {
        try {
            if ($input->dryRun && $input->check) {
                throw new InvalidArgumentException('--dry-run and --check are mutually exclusive.');
            }
            $plan = $this->planner->plan($this->options->create($input->release->values()), $operation);
            $verified = ! $input->check || $this->applier->isApplied($plan);
            if (! $input->dryRun && ! $input->check && 'none' !== $plan->mode()) {
                $this->applier->apply($plan);
            }
            $output->writeln(
                json_encode($plan->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );

            return $verified ? Command::SUCCESS : Command::FAILURE;
        } catch (Throwable $exception) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

            return $exception instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }
}
