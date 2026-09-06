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
 * Renders one release body to stdout or to a requested file.
 */
#[AsCommand(
    name: 'changelog:render-release-notes',
    description: 'Render a changelog section into a release-notes body or output file.',
    aliases: ['changelog:show', 'changelog:release-notes'],
)]
final class ReleaseNotesRenderCommand extends Command
{
    /**
     * Initializes the release renderer and output path collaborator.
     *
     * @param ChangelogManagerInterface $changelogManager renders release contents
     * @param PackageFilesystemInterface $filesystem resolves and writes paths
     */
    public function __construct(
        private readonly ChangelogManagerInterface $changelogManager,
        private readonly PackageFilesystemInterface $filesystem,
    ) {
        parent::__construct();
    }

    /**
     * Configures version, source file, output file, and base directory inputs.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'Released changelog version to render.')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the changelog file.', 'CHANGELOG.md')
            ->addOption('output-file', null, InputOption::VALUE_REQUIRED, 'Write release notes to a file.')
            ->addOption('working-dir', null, InputOption::VALUE_REQUIRED, 'Base directory for relative paths.', '.');
    }

    /**
     * Writes release notes to stdout unless an output file was supplied.
     *
     * Standard output MUST use raw mode so Markdown fragments that resemble
     * Symfony formatter tags remain byte-for-byte unchanged.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDirectory = (string) $input->getOption('working-dir');
        $file = $this->filesystem->getAbsolutePath((string) $input->getOption('file'), $workingDirectory);
        $releaseNotes = $this->changelogManager->renderReleaseNotes(
            $file,
            (string) $input->getArgument('version'),
        );
        $outputFile = trim((string) $input->getOption('output-file'));

        if ('' === $outputFile) {
            $output->write($releaseNotes, false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $absoluteOutputFile = $this->filesystem->getAbsolutePath($outputFile, $workingDirectory);
        $this->filesystem->dumpFile($absoluteOutputFile, $releaseNotes);
        $output->writeln(\sprintf('<info>Release notes rendered to %s.</info>', $absoluteOutputFile));

        return self::SUCCESS;
    }
}
