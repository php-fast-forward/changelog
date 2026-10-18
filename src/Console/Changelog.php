<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Console;

use Closure;
use FastForward\Changelog\Version\PackageVersionResolverInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Provides the standalone Symfony Console application for changelog commands.
 *
 * Command construction MUST remain delegated to the injected loader so an
 * invalid command dependency cannot prevent unrelated commands from loading.
 */
final class Changelog extends Application
{
    /**
     * Initializes application metadata and installs the lazy command loader.
     *
     * @param CommandLoaderInterface                            $commandLoader   exposes command metadata lazily
     * @param PackageVersionResolverInterface                   $versionResolver resolves package metadata
     * @param Closure(InputInterface,OutputInterface):void|null $skillWarning    Optional lazy local warning; excluded commands never resolve it.
     */
    public function __construct(
        CommandLoaderInterface $commandLoader,
        PackageVersionResolverInterface $versionResolver,
        private readonly ?Closure $skillWarning = null,
    ) {
        parent::__construct('Fast Forward Changelog', $versionResolver->resolve());

        $this->setCommandLoader($commandLoader);
    }

    /**
     * Converts GitHub command binding/validation failures to the machine contract before services execute.
     * Add's required-input failures keep human Symfony diagnostics and the existing invalid-input exit status.
     * Other commands retain Symfony errors; successful invocations keep their existing result and exit status.
     *
     * @param Command         $command Selected Symfony command whose execution or binding failure is handled.
     * @param InputInterface  $input   Bound Symfony arguments and options for the selected command.
     * @param OutputInterface $output  Output stream receiving machine results or controlled command diagnostics.
     *
     * @return int Selected command's exit status, preserving the machine contract for GitHub binding failures.
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $this->warnAboutSkill($command, $input, $output);
        try {
            return parent::doRunCommand($command, $input, $output);
        } catch (ExceptionInterface $exception) {
            if ('add' === $command->getName()) {
                $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                $this->renderThrowable($exception, $error);

                return Command::INVALID;
            }

            if ('github' !== $command->getName()) {
                throw $exception;
            }
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln(
                json_encode(
                    ['error' => $exception->getMessage()],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                ),
                OutputInterface::OUTPUT_RAW,
            );

            return Command::INVALID;
        }
    }

    /**
     * Invokes one optional warning only on selected human command paths with a separate error stream.
     *
     * @param  Command         $command Selected top-level command whose ordinary result remains unchanged.
     * @param  InputInterface  $input   Raw flags/interactivity used to exclude machine and unattended consumers.
     * @param  OutputInterface $output  Human console output that must provide separate stderr.
     * @return void            Excluded paths perform no skill lookup; auxiliary warning failures remain nonfatal.
     */
    private function warnAboutSkill(Command $command, InputInterface $input, OutputInterface $output): void
    {
        if (null === $this->skillWarning || ! $output instanceof ConsoleOutputInterface) {
            return;
        }
        if ($output->isQuiet() || ! $input->isInteractive()) {
            return;
        }
        if (! in_array($command->getName(), ['add', 'check', 'help', 'list'], true)
            || $input->hasParameterOption(['--raw', '--json', '-j'], true)) {
            return;
        }
        try {
            ($this->skillWarning)($input, $output->getErrorOutput());
        } catch (Throwable) {
            // An optional local hint cannot prevent command binding, validation or ordinary execution.
        }
    }
}
