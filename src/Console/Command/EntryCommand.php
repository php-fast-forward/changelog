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
use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Entry\ChangelogEntryTypesInterface;
use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Adds one categorized entry to a changelog release section.
 */
#[AsCommand(
    name: 'changelog:entry',
    description: 'Add a changelog entry to Unreleased or a specific version section.',
)]
final class EntryCommand extends Command
{
    /**
     * Initializes the collaborators used to normalize and persist an entry.
     *
     * @param ChangelogManagerInterface $changelogManager performs the mutation
     * @param PackageFilesystemInterface $filesystem resolves the target path
     * @param ChangelogEntryTypesInterface $entryTypes normalizes category input
     * @param ReleaseDateValidatorInterface $dateValidator rejects invalid release dates
     */
    public function __construct(
        private readonly ChangelogManagerInterface $changelogManager,
        private readonly PackageFilesystemInterface $filesystem,
        private readonly ChangelogEntryTypesInterface $entryTypes,
        private readonly ReleaseDateValidatorInterface $dateValidator,
    ) {
        parent::__construct();
    }

    /**
     * Configures the entry text, category, release, date, and path options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::REQUIRED, 'The changelog entry text to append.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'The changelog category.', 'added')
            ->addOption(
                'release',
                null,
                InputOption::VALUE_REQUIRED,
                'The target release section. Defaults to Unreleased.',
                ChangelogDocument::UNRELEASED_VERSION,
            )
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Optional release date in YYYY-MM-DD format.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the changelog file.', 'CHANGELOG.md')
            ->addOption('working-dir', null, InputOption::VALUE_REQUIRED, 'Base directory for relative paths.', '.');
    }

    /**
     * Resolves inputs and delegates the entry mutation to the manager.
     *
     * The command MUST return success only after the manager accepts the entry.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $date = $input->getOption('date');

        if (\is_string($date)) {
            $this->dateValidator->validate($date);
        }

        $file = $this->filesystem->getAbsolutePath(
            (string) $input->getOption('file'),
            (string) $input->getOption('working-dir'),
        );
        $type = $this->entryTypes->fromInput((string) $input->getOption('type'));
        $release = (string) $input->getOption('release');

        $this->changelogManager->addEntry(
            $file,
            $type,
            (string) $input->getArgument('message'),
            $release,
            \is_string($date) ? $date : null,
        );

        $output->writeln(\sprintf(
            '<info>Added %s changelog entry to [%s] in %s.</info>',
            strtolower($type->value),
            $release,
            $file,
        ));

        return self::SUCCESS;
    }
}
