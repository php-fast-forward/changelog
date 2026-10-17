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
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Publication\PublicationServiceInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Publishes approved evidence at a complete resolved commit identity. */
#[AsCommand(
    name: 'publish',
    description: 'Publish the approved release evidence for a selected commit.',
)]
final readonly class PublishCommand
{
    /**
     * Captures publication and commit resolution without contacting Git or GitHub.
     *
     * @param ReleaseOptionsFactoryInterface $options     Creates validated consumer settings from mapped CLI values.
     * @param GitRepositoryInterface         $git         Observes repository identities, committed bytes and scoped operations through an injected boundary.
     * @param PublicationServiceInterface    $publication Validates approved committed evidence and reconciles create-only tag/release state.
     */
    public function __construct(
        private ReleaseOptionsFactoryInterface $options,
        private GitRepositoryInterface $git,
        private PublicationServiceInterface $publication,
    ) {}

    /**
     * Defaults the target to checked-out HEAD and passes a complete SHA to the publication contract.
     *
     * @param ReleaseInput    $settings  Mapped shared consumer paths and release settings from Symfony input.
     * @param OutputInterface $output    Output stream receiving machine results or controlled command diagnostics.
     * @param string|null     $targetSha Explicit approved commit selection; null resolves the checked-out HEAD.
     * @param bool            $dryRun    Whether to prove the operation and inspect state without applying its writes.
     *
     * @return int Symfony exit status reflecting the command's result or controlled input/operation failure.
     */
    public function __invoke(
        #[MapInput]
        ReleaseInput $settings,
        OutputInterface $output,

        #[Option(description: 'Approved commit or revision; defaults to checked-out HEAD.', shortcut: 't')]
        ?string $targetSha = null,

        #[Option(description: 'Inspect publication actions without changing GitHub state.', shortcut: 'd')]
        bool $dryRun = false,
    ): int {
        try {
            $options = $this->options->create($settings->values());
            $sha = $this->git->resolveRef($options->workingDirectory, $targetSha ?? 'HEAD');
            $result = $this->publication->publish($options, $sha, $dryRun);
            $output->writeln(
                json_encode($result->summary(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

            return $exception instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }
}
