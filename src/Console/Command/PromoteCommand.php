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

use FastForward\Changelog\Date\ReleaseDateValidatorInterface;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Promotes Unreleased entries into a published version.
 */
#[AsCommand(
    name: 'changelog:promote',
    description: 'Promote Unreleased entries into a published changelog version.',
)]
final class PromoteCommand extends Command
{
    /**
     * Initializes promotion collaborators and the deterministic date source.
     *
     * @param ChangelogManagerInterface $changelogManager performs promotion
     * @param PackageFilesystemInterface $filesystem resolves the target path
     * @param ClockInterface $clock supplies a deterministic default release date
     * @param ReleaseDateValidatorInterface $dateValidator rejects invalid release dates
     */
    public function __construct(
        private readonly ChangelogManagerInterface $changelogManager,
        private readonly PackageFilesystemInterface $filesystem,
        private readonly ClockInterface $clock,
        private readonly ReleaseDateValidatorInterface $dateValidator,
    ) {
        parent::__construct();
    }

    /**
     * Configures the release version, optional date, and path options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'The semantic version to publish.')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'The release date in YYYY-MM-DD format.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the changelog file.', 'CHANGELOG.md')
            ->addOption('working-dir', null, InputOption::VALUE_REQUIRED, 'Base directory for relative paths.', '.');
    }

    /**
     * Promotes Unreleased using the explicit date or the injected clock date.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dateOption = $input->getOption('date');
        $date = \is_string($dateOption) && '' !== $dateOption
            ? $dateOption
            : $this->clock->now()->format('Y-m-d');
        $this->dateValidator->validate($date);

        $file = $this->filesystem->getAbsolutePath(
            (string) $input->getOption('file'),
            (string) $input->getOption('working-dir'),
        );
        $version = (string) $input->getArgument('version');

        $this->changelogManager->promote($file, $version, $date);
        $output->writeln(\sprintf(
            '<info>Promoted Unreleased changelog entries to [%s] in %s.</info>',
            $version,
            $file,
        ));

        return self::SUCCESS;
    }
}
