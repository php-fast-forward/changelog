<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://github.com/php-fast-forward/changelog/issues
 * @see       https://php-fast-forward.github.io/changelog/
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Console\Command;

use FastForward\Changelog\Checker\UnreleasedEntryCheckerInterface;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Validates that Unreleased contains a meaningful new entry.
 */
#[AsCommand(
    name: 'changelog:check',
    description: 'Check whether a changelog file contains meaningful unreleased entries.',
)]
final class CheckCommand extends Command
{
    /**
     * Initializes path resolution and baseline comparison collaborators.
     *
     * @param PackageFilesystemInterface $filesystem resolves the changelog path
     * @param UnreleasedEntryCheckerInterface $checker performs baseline comparison
     */
    public function __construct(
        private readonly PackageFilesystemInterface $filesystem,
        private readonly UnreleasedEntryCheckerInterface $checker,
    ) {
        parent::__construct();
    }

    /**
     * Configures the optional baseline and changelog path options.
     */
    protected function configure(): void
    {
        $this
            ->addOption('against', null, InputOption::VALUE_REQUIRED, 'Git reference used as the baseline.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the changelog file.', 'CHANGELOG.md')
            ->addOption('working-dir', null, InputOption::VALUE_REQUIRED, 'Base directory for relative paths.', '.');
    }

    /**
     * Returns success only when a meaningful Unreleased change is present.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->filesystem->getAbsolutePath(
            (string) $input->getOption('file'),
            (string) $input->getOption('working-dir'),
        );
        $workingDirectory = $this->filesystem->getAbsolutePath(
            '.',
            (string) $input->getOption('working-dir'),
        );
        $against = $input->getOption('against');

        if ($this->checker->hasPendingChanges(
            $file,
            \is_string($against) ? $against : null,
            $workingDirectory,
        )) {
            $output->writeln('<info>The changelog contains unreleased changes ready for review.</info>');

            return self::SUCCESS;
        }

        $output->writeln('<error>The changelog must add a meaningful entry to the Unreleased section.</error>');

        return self::FAILURE;
    }
}
