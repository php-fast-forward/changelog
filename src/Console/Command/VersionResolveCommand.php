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

use FastForward\Changelog\Filesystem\PackageFilesystemInterface;
use FastForward\Changelog\Manager\ChangelogManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resolves an explicit release version or infers the next version.
 */
#[AsCommand(
    name: 'changelog:resolve-version',
    description: 'Resolve the release version from input or infer it from Unreleased entries.',
    aliases: ['changelog:next-version'],
)]
final class VersionResolveCommand extends Command
{
    /**
     * Initializes explicit-path resolution and version inference collaborators.
     *
     * @param ChangelogManagerInterface $changelogManager performs inference
     * @param PackageFilesystemInterface $filesystem resolves the changelog path
     */
    public function __construct(
        private readonly ChangelogManagerInterface $changelogManager,
        private readonly PackageFilesystemInterface $filesystem,
    ) {
        parent::__construct();
    }

    /**
     * Configures explicit version, current version, and path inputs.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Explicit release version.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the changelog file.', 'CHANGELOG.md')
            ->addOption('current-version', null, InputOption::VALUE_REQUIRED, 'Explicit inference base version.')
            ->addOption('working-dir', null, InputOption::VALUE_REQUIRED, 'Base directory for relative paths.', '.');
    }

    /**
     * Emits the explicit version without loading the file, otherwise infers it.
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
