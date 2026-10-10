<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Console\Command;

use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Automation\Output\GitHubOutputWriterInterface;
use FastForward\Changelog\Console\Input\GitHubInput;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Exposes the existing trusted automation services through the ordinary packaged CLI. */
#[AsCommand(
    name: 'github',
    description: 'Run an explicit GitHub check, Dependabot, version, publish or history operation.',
)]
final readonly class GitHubCommand
{
    /** Injects orchestration and output boundaries without executing an operation. */
    public function __construct(
        private AutomationRunnerInterface $runner,
        private GitHubOutputWriterInterface $writer,
    ) {}

    /** Returns JSON on stdout; validation and operational failures use JSON diagnostics and distinct exit statuses. */
    public function __invoke(
        #[Argument(description: 'Operation: check, dependabot, version, publish or history.', suggestedValues: [
            'check',
            'dependabot',
            'version',
            'publish',
            'history',
        ])]
        string $operation,

        #[MapInput]
        GitHubInput $input,
        OutputInterface $output,
    ): int {
        try {
            $result = $this->runner->run($operation, $input->values());
            $output->writeln($this->writer->write($result), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln(
                json_encode(
                    ['error' => $exception->getMessage()],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                ),
                OutputInterface::OUTPUT_RAW,
            );

            return $exception instanceof InvalidArgumentException || $exception instanceof JsonException ? Command::INVALID : Command::FAILURE;
        }
    }
}
