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

use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\History\HistoryCodecInterface;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\Import\HistoryImporterInterface;
use FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface;
use FastForward\Changelog\Template\TemplateResolverInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/** Returns the exact maintained release body selected by explicit or observed version evidence. */
#[AsCommand(
    name: 'notes',
    description: 'Read exact release notes from the maintained changelog.',
)]
final readonly class NotesCommand
{
    /** Injects every source of version evidence and file access without performing I/O. */
    public function __construct(
        private ReleaseOptionsFactoryInterface $options,
        private PackagePathResolverInterface $paths,
        private ManagedFileStoreInterface $files,
        private HistoryCodecInterface $history,
        private TemplateResolverInterface $templates,
        private GitRepositoryInterface $git,
        private HistoryImporterInterface $importer,
        private ChangesetStoreInterface $fragments,
        private LockFactory $locks,
    ) {}

    /** Uses the highest maintained stable SemVer independently of section order, then reachable stable tags. */
    public function __invoke(
        #[MapInput]
        ReleaseInput $settings,
        OutputInterface $output,

        #[Argument(
            description: 'Version to read; defaults to the highest maintained stable version or current stable Git tag.',
        )]
        ?string $version = null,

        #[Option(description: 'Optional project-relative managed output file.', name: 'output')]
        ?string $outputFile = null,
    ): int {
        try {
            $options = $this->options->create($settings->values());
            $contents = $this->files->read(
                $this->paths->absolutePath($options->changelogFile, $options->workingDirectory),
            );
            if (null === $contents) {
                throw new InvalidArgumentException('The maintained changelog does not exist.');
            }
            if (null === $version) {
                $template = $this->templates->resolve($options);
                $document = $this->history->parse($contents, $template);
                $version = $this->latestStableVersion($document);
                if (null === $version) {
                    $tags = [];
                    if ($this->git->isRepository($options->workingDirectory)) {
                        $head = $this->git->resolveRef($options->workingDirectory, 'HEAD');
                        $tags = array_values(array_filter(
                            $this->git->tags($options->workingDirectory),
                            fn(array $tag): bool => $this->git->isAncestor(
                                $options->workingDirectory,
                                $tag['sha'],
                                $head,
                            ),
                        ));
                    }
                    $version = $this->importer->currentVersion($tags, $options->tagPrefix);
                }
            }
            $template ??= $this->templates->resolve($options);
            $document ??= $this->history->parse($contents, $template);
            $notes = $this->history->notes($document, $version);
            if (null === $outputFile) {
                $output->write($notes, false, OutputInterface::OUTPUT_RAW);
            } else {
                $segments = explode('/', str_replace('\\', '/', $outputFile));
                if ('' === trim($outputFile) || str_contains($outputFile, "\0") || $this->paths->isAbsolute($outputFile)
                    || array_intersect(['', '.', '..'], $segments)) {
                    throw new InvalidArgumentException(
                        '--output must identify a canonical relative project file without traversal.',
                    );
                }
                $target = $this->paths->absolutePath($outputFile, $options->workingDirectory);
                $comparableTarget = strtolower(str_replace('\\', '/', $target));
                $central = strtolower(
                    str_replace('\\', '/', $this->paths->absolutePath(
                        $options->changelogFile,
                        $options->workingDirectory,
                    )),
                );
                $directory = strtolower(
                    str_replace('\\', '/', $this->paths->absolutePath(
                        $options->fragmentDirectory,
                        $options->workingDirectory,
                    )),
                );
                if ($comparableTarget === $central || $comparableTarget === $directory || str_starts_with(
                    $comparableTarget,
                    $directory . '/',
                )) {
                    throw new InvalidArgumentException(
                        '--output must be outside the maintained changelog and fragment directory.',
                    );
                }
                $resource = $this->fragments->lockResource(
                    $this->paths->absolutePath($options->fragmentDirectory, $options->workingDirectory),
                );
                $lock = $this->locks->createLock($resource);
                if (! $lock->acquire()) {
                    throw new RuntimeException('Unable to acquire the managed-file lock for notes output.');
                }
                try {
                    if (null !== $this->files->read($target)) {
                        throw new InvalidArgumentException(
                            '--output must identify a new file; existing files cannot be overwritten.',
                        );
                    }
                    $this->files->write($target, $notes);
                } finally {
                    $lock->release();
                }
            }

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

            return $exception instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }

    /** Returns the highest strict stable maintained identity without using presentation order or dates. */
    private function latestStableVersion(HistoryDocument $document): ?string
    {
        $latest = null;
        foreach ($document->getReleases() as $release) {
            $candidate = $release->getVersion();
            if (1 !== preg_match(
                '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/',
                $candidate,
            )) {
                continue;
            }
            if (null === $latest || $this->compareStableVersions($candidate, $latest) > 0) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /** Compares arbitrary-width core numbers exactly; equal precedence uses deterministic build-metadata ordering. */
    private function compareStableVersions(string $left, string $right): int
    {
        $leftParts = explode('.', explode('+', $left, 2)[0]);
        $rightParts = explode('.', explode('+', $right, 2)[0]);
        foreach ($leftParts as $index => $component) {
            $comparison = strlen($component) <=> strlen($rightParts[$index]);
            if (0 === $comparison) {
                $comparison = strcmp($component, $rightParts[$index]);
            }
            if (0 !== $comparison) {
                return $comparison;
            }
        }

        return strcmp($left, $right);
    }

}
