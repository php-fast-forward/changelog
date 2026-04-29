<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Console\Command;

use DateTimeImmutable;
use FastForward\Changelog\Filesystem\PackageFilesystem;
use FastForward\Changelog\Manager\ChangelogManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Safe\getcwd;

#[AsCommand(
    name: 'changelog:promote',
    description: 'Promote Unreleased entries into a published changelog version.',
)]
final class ChangelogPromoteCommand extends Command
{
    public function __construct(
        private readonly ChangelogManager $changelogManager,
        private readonly PackageFilesystem $filesystem,
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addArgument(
                name: 'version',
                mode: InputArgument::REQUIRED,
                description: 'The semantic version that should receive the current Unreleased entries.',
            )
            ->addOption(
                name: 'date',
                mode: InputOption::VALUE_REQUIRED,
                description: 'The release date to record in YYYY-MM-DD format.',
            )
            ->addOption(
                name: 'file',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Path to the changelog file.',
                default: 'CHANGELOG.md',
            )
            ->addOption(
                name: 'working-dir',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Working directory used to resolve relative paths.',
                default: getcwd() ?: '.',
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $this->filesystem->getAbsolutePath(
            (string) $input->getOption('file'),
            (string) $input->getOption('working-dir'),
        );
        $version = (string) $input->getArgument('version');
        $date = (string) ($input->getOption('date') ?: (new DateTimeImmutable('now'))->format('Y-m-d'));

        $this->changelogManager->promote($file, $version, $date);
        $io->success(\sprintf('Promoted Unreleased changelog entries to [%s] in %s.', $version, $file));

        return self::SUCCESS;
    }
}
