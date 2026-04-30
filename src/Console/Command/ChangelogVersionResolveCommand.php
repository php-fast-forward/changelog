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

use FastForward\Changelog\Filesystem\PackageFilesystem;
use FastForward\Changelog\Manager\ChangelogManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Safe\getcwd;

#[AsCommand(
    name: 'changelog:resolve-version',
    description: 'Resolve the release version from input or infer it from Unreleased entries.',
    aliases: ['changelog:next-version'],
)]
final class ChangelogVersionResolveCommand extends Command
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
                mode: InputArgument::OPTIONAL,
                description: 'Explicit release version. When omitted, infer it from the changelog.',
            )
            ->addOption(
                name: 'file',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Path to the changelog file.',
                default: 'CHANGELOG.md',
            )
            ->addOption(
                name: 'current-version',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Explicit current version used as the bump base for inference.',
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
        $version = trim((string) $input->getArgument('version'));

        if ('' === $version) {
            $file = $this->filesystem->getAbsolutePath(
                (string) $input->getOption('file'),
                (string) $input->getOption('working-dir'),
            );
            $currentVersion = $input->getOption('current-version');
            $version = $this->changelogManager->inferNextVersion(
                $file,
                \is_string($currentVersion) ? $currentVersion : null,
            );
        }

        $output->writeln($version);

        return self::SUCCESS;
    }
}
