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
use FastForward\Changelog\Release\ReleaseOptions;
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
    /**
     * Injects every source of version evidence and file access without performing I/O.
     *
     * @param ReleaseOptionsFactoryInterface $options   Creates validated consumer settings from mapped CLI values.
     * @param PackagePathResolverInterface   $paths     Resolves explicit consumer paths without filesystem observation.
     * @param ManagedFileStoreInterface      $files     Reads and writes guarded managed bytes through an injected filesystem boundary.
     * @param HistoryCodecInterface          $history   Parses, renders and extracts exact maintained Markdown through the pure history boundary.
     * @param TemplateResolverInterface      $templates Resolves built-in or explicitly selected trusted PHP presentation through injected boundaries.
     * @param GitRepositoryInterface         $git       Observes repository identities, committed bytes and scoped operations through an injected boundary.
     * @param HistoryImporterInterface       $importer  Resolves supported stable tags and imports only missing historical versions.
     * @param ChangesetStoreInterface        $fragments Injected fragment store sharing the directory-scoped transaction lock and exact managed-path policy.
     * @param LockFactory                    $locks     Builds cooperative directory locks shared by fragment writers and managed release operations.
     */
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

    /**
     * Uses the highest maintained stable SemVer independently of section order, then reachable stable tags.
     *
     * @param ReleaseInput    $settings   Mapped shared consumer paths and release settings from Symfony input.
     * @param OutputInterface $output     Output stream receiving machine results or controlled command diagnostics.
     * @param string|null     $version    Explicit maintained release identity, or null to select the highest stable section before reachable Git tags.
     * @param string|null     $outputFile Optional new project-relative notes destination outside central-history and fragment inputs.
     *
     * @return int Symfony exit status reflecting the command's result or controlled input/operation failure.
     */
    public function __invoke(
        #[MapInput]
        ReleaseInput $settings,
        OutputInterface $output,

        #[Argument(
            description: 'Version to read; defaults to the highest maintained stable version or current stable Git tag.',
        )]
        ?string $version = null,

        #[Option(description: 'Optional project-relative managed output file.', name: 'output', shortcut: 'o')]
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
                $version ??= $this->latestTaggedVersion($options);
            }
            $template ??= $this->templates->resolve($options);
            $document ??= $this->history->parse($contents, $template);
            $notes = $this->history->notes($document, $version);
            if (null === $outputFile) {
                $output->write($notes, false, OutputInterface::OUTPUT_RAW);
            } else {
                $this->writeNotesFile($options, $outputFile, $notes);
            }

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

            return $exception instanceof InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
    }

    /**
     * Resolves the latest reachable stable tag when maintained history has no stable release.
     *
     * @param ReleaseOptions $options Repository directory and tag prefix used for version discovery.
     *
     * @return string Reachable stable version, or the importer's empty-history baseline.
     */
    private function latestTaggedVersion(ReleaseOptions $options): string
    {
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

        return $this->importer->currentVersion($tags, $options->tagPrefix);
    }

    /**
     * Writes notes to a new managed file while holding the fragment-store lock.
     *
     * @param ReleaseOptions $options    Paths defining the package's managed changelog and fragment directory.
     * @param string         $outputFile Canonical project-relative destination outside those managed paths.
     * @param string         $notes      Exact maintained release body to persist without formatting changes.
     *
     * @return void Creates the file, or fails without overwriting an existing destination.
     *
     * @throws \InvalidArgumentException When the output path is unsafe, overlaps maintained inputs or already exists.
     * @throws \RuntimeException         When the directory lock cannot be acquired or guarded output I/O fails.
     */
    private function writeNotesFile(ReleaseOptions $options, string $outputFile, string $notes): void
    {
        $target = $this->outputTarget($options, $outputFile);
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

    /**
     * Validates an output destination before acquiring a lock or writing bytes.
     *
     * @param ReleaseOptions $options    Package paths that must not be overwritten by notes output.
     * @param string         $outputFile Supplied project-relative destination.
     *
     * @return string Absolute target outside the central history and fragment directory.
     *
     * @throws \InvalidArgumentException When the destination is not a canonical project-relative file outside maintained history and fragments.
     */
    private function outputTarget(ReleaseOptions $options, string $outputFile): string
    {
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

        return $target;
    }

    /**
     * Returns the highest strict stable maintained identity without using presentation order or dates.
     *
     * @param HistoryDocument $document Maintained history whose release bodies, introduction and references must be preserved.
     *
     * @return string|null Highest strict stable maintained identity, or null when only pending or prerelease sections exist.
     */
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

    /**
     * Compares arbitrary-width core numbers exactly; equal precedence uses deterministic build-metadata ordering.
     *
     * @param string $left  First already validated semantic version being compared without machine-integer conversion.
     * @param string $right Second already validated semantic version; build metadata deterministically breaks equal numeric precedence.
     *
     * @return int Negative, zero or positive order by arbitrary-width core components, then lexical build metadata for ties.
     */
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
