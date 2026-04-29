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

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Entry\ChangelogEntryType;
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
    name: 'changelog:entry',
    description: 'Add a changelog entry to Unreleased or a specific version section.',
)]
final class ChangelogEntryCommand extends Command
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
                name: 'message',
                mode: InputArgument::REQUIRED,
                description: 'The changelog entry text to append.',
            )
            ->addOption(
                name: 'type',
                shortcut: 't',
                mode: InputOption::VALUE_REQUIRED,
                description: 'The changelog category (added, changed, deprecated, removed, fixed, security).',
                default: 'added',
            )
            ->addOption(
                name: 'release',
                mode: InputOption::VALUE_REQUIRED,
                description: 'The target release section. Defaults to Unreleased.',
                default: ChangelogDocument::UNRELEASED_VERSION,
            )
            ->addOption(
                name: 'date',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Optional release date for published sections in YYYY-MM-DD format.',
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
        $type = ChangelogEntryType::fromInput((string) $input->getOption('type'));
        $release = (string) $input->getOption('release');
        $date = $input->getOption('date');
        $message = (string) $input->getArgument('message');

        $this->changelogManager->addEntry(
            $file,
            $type,
            $message,
            $release,
            \is_string($date) ? $date : null,
        );

        $io->success(\sprintf('Added %s changelog entry to [%s] in %s.', strtolower($type->value), $release, $file));

        return self::SUCCESS;
    }
}
