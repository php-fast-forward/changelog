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

use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Release\ReleasePlannerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Reports the immutable plan summary as machine-readable JSON without progress output. */
#[AsCommand(name: 'status', description: 'Show the current release plan as JSON without writing files.')]
final readonly class StatusCommand
{
    /** Captures planning contracts without accessing files, Git or the network. */
    public function __construct(private ReleaseOptionsFactoryInterface $options, private ReleasePlannerInterface $planner) {}

    /** Prints only the shared summary; --json is accepted for explicit machine consumers. */
    public function __invoke(#[MapInput] ReleaseInput $settings, OutputInterface $output, #[Option(description: 'Explicitly select the machine-readable JSON output.')] bool $json = false): int
    {
        try {
            $plan = $this->planner->plan($this->options->create($settings->values()));
            $output->writeln(json_encode($plan->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');
            return $exception instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }
}
